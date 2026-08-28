<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Models\TicketFirstResponseMetric;
use App\Models\TicketStatusMetric;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * StatusChangedHandler — Xử lý sự kiện thay đổi trạng thái Ticket.
 *
 * Xử lý 5 luồng chuyển trạng thái không dồn thời gian Pause/End:
 * - Run → Pause: tạm dừng RT, TTR, groups; bắt đầu Waiting/Pending
 * - Run → End: kết thúc tất cả; ghi nhận closed_at
 * - Pause → Run: tiếp tục đếm; cộng waiting vào due dates
 * - Pause → End: kết thúc tất cả; cập nhật due dates
 * - End → Run (reopen): tính closed_total; tiếp tục đếm
 */
class StatusChangedHandler
{
    protected TimerService $timerService;
    protected SlaInitializationService $initService;
    protected TimelineService $timelineService;
    protected SlaStageService $stageService;

    public function __construct(
        TimerService $timerService,
        SlaInitializationService $initService,
        TimelineService $timelineService,
        ?SlaStageService $stageService = null
    ) {
        $this->timerService = $timerService;
        $this->initService = $initService;
        $this->timelineService = $timelineService;
        $this->stageService = $stageService ?? new SlaStageService();
    }

    public function handle(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();

        $eventUpdatedAt = null;
        $eventData = $event->event_data ?? [];
        $updatedAtRaw = $eventData['ticket_data']['updated_at'] ?? null;
        if ($updatedAtRaw) {
            $eventUpdatedAt = Carbon::parse($updatedAtRaw);
        }

        $this->initService->ensureSlaInitialized($ticket, $eventUpdatedAt);

        $statusChange = collect($changes)->firstWhere('field', 'status');

        if (!$statusChange) {
            Log::warning("StatusChangedHandler: Không tìm thấy thay đổi status trong mảng changes", ['ticket_id' => $ticketId]);
            return;
        }

        $oldStatus = $this->timerService->canonicalizeStatus(
            $statusChange['old_value'] ?? $ticket->status
        ) ?? $ticket->status;
        $newStatus = $this->timerService->canonicalizeStatus(
            $statusChange['new_value'] ?? $ticketData['status'] ?? $ticket->status
        ) ?? $ticket->status;

        Log::info("StatusChangedHandler: {$oldStatus} → {$newStatus}", ['ticket_id' => $ticketId]);

        $ticket->status = $newStatus;

        $this->recalculateSlaOnStatusChange($ticket, $oldStatus, $newStatus, $eventUpdatedAt, $event);

        $ticket->save();

        $this->timelineService->appendTicketEventLog($ticket, 's', $this->timerService->getShortStatus($newStatus), $event->event_timestamp, null, $event);

        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        if ($ttrMetric->latest_due_date_ttr) {
            $this->timelineService->appendTicketEventLog($ticket, 'd', $ttrMetric->latest_due_date_ttr->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp, null, $event);
        }
        if ($rtMetric->latest_due_date_rt) {
            $this->timelineService->appendTicketEventLog($ticket, 'fr', $rtMetric->latest_due_date_rt->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp, null, $event);
        }
    }

    protected function recalculateSlaOnStatusChange(Ticket $ticket, string $oldStatus, string $newStatus, ?Carbon $eventUpdatedAt, TicketEvent $event): void
    {
        $now = $eventUpdatedAt ?? now();

        $wasRunning = $this->timerService->isRunStatus($oldStatus);
        $wasPaused = $this->timerService->isPauseStatus($oldStatus);
        $wasEnded = $this->timerService->isEndStatus($oldStatus);
        $isRunning = $this->timerService->isRunStatus($newStatus);
        $isPaused = $this->timerService->isPauseStatus($newStatus);
        $isEnded = $this->timerService->isEndStatus($newStatus);

        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        $statusMetric = $ticket->getOrCreateStatusMetric();

        if ($wasRunning && $isPaused) {
            $this->handleRunToPause($ticket, $rtMetric, $statusMetric, $newStatus, $now, $event);
        }

        if ($wasRunning && $isEnded) {
            $this->handleRunToEnd($ticket, $rtMetric, $statusMetric, $newStatus, $now, $event);
        }

        if ($wasPaused && $isRunning) {
            $this->handlePauseToRun($ticket, $rtMetric, $statusMetric, $oldStatus, $now);
        }

        if ($wasPaused && $isEnded) {
            $this->handlePauseToEnd($ticket, $rtMetric, $statusMetric, $oldStatus, $newStatus, $now, $event);
        }

        if ($wasPaused && $isPaused && $oldStatus !== $newStatus) {
            $this->handlePauseToPause($statusMetric, $oldStatus, $newStatus, $now);
        }

        if ($wasEnded && $isRunning) {
            $this->handleEndToRun($ticket, $statusMetric, $oldStatus, $now, $event);
        }

        if ($wasEnded && $isEnded && $oldStatus !== $newStatus) {
            $this->handleEndToEnd($ticket, $statusMetric, $newStatus, $now);
        }

        $rtMetric->save();
        $statusMetric->save();
        $ticket->setRelation('statusMetric', $statusMetric);
        $ticket->setRelation('firstResponseMetric', $rtMetric);

        $this->timerService->recalculateGroupMetrics($ticket, [], $now);
        $this->timerService->recalculateRtMetrics($rtMetric);

        $rtMetric->save();
    }

    protected function handleRunToPause(
        Ticket $ticket,
        TicketFirstResponseMetric $rtMetric,
        TicketStatusMetric $statusMetric,
        string $newStatus,
        Carbon $now,
        TicketEvent $event
    ): void {
        $this->timerService->stopAllActiveGroupTimers($ticket, $now);

        if (!$rtMetric->hasFirstResponse() && $rtMetric->status === 'running') {
            $this->timerService->accumulateRtUsedTime($rtMetric, $now);
            $rtMetric->status = 'paused';
        }

        if ($newStatus === 'Waiting For Customer') {
            $statusMetric->waiting_started_at = $now;
        } elseif ($newStatus === 'Pending') {
            $statusMetric->pending_started_at = $now;
        }
    }

    protected function handleRunToEnd(
        Ticket $ticket,
        TicketFirstResponseMetric $rtMetric,
        TicketStatusMetric $statusMetric,
        string $newStatus,
        Carbon $now,
        TicketEvent $event
    ): void {
        $this->timerService->stopAllActiveGroupTimers($ticket, $now);
        $isFirstEnd = !$ticket->resolved_at && !$ticket->closed_at;

        if (!$rtMetric->hasFirstResponse()) {
            $this->timerService->accumulateRtUsedTime($rtMetric, $now);
            $rtMetric->status = 'ended_closed_no_reply';
        }

        if ($isFirstEnd) {
            $this->timerService->finalizeTtrUsedTime($ticket, $statusMetric, $now);
        }

        $this->recordFirstEndTimestamp($ticket, $newStatus, $now);
        $this->timerService->finalizeResolutionTime($ticket, $statusMetric);
        $this->finalizeOpenStageOnTicketEnd($ticket, $now, $event);
    }

    protected function handlePauseToRun(
        Ticket $ticket,
        TicketFirstResponseMetric $rtMetric,
        TicketStatusMetric $statusMetric,
        string $oldStatus,
        Carbon $now
    ): void {
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $waitingDuration = $this->timerService->getLastWaitingDuration($statusMetric, $oldStatus, $now);

        $this->timerService->accumulateWaitingTime($statusMetric, $oldStatus, $now);

        if ($ttrMetric->processing_mode !== 'due-driven') {
            if ($ttrMetric->latest_due_date_ttr) {
                $ttrMetric->latest_due_date_ttr = Carbon::parse($ttrMetric->latest_due_date_ttr)->addSeconds($waitingDuration);
            }
            if (!$rtMetric->hasFirstResponse() && $rtMetric->latest_due_date_rt) {
                $rtMetric->latest_due_date_rt = Carbon::parse($rtMetric->latest_due_date_rt)->addSeconds($waitingDuration);
            }
        }

        if (!$rtMetric->hasFirstResponse() && $rtMetric->status === 'paused') {
            $rtMetric->status = 'running';
            $rtMetric->started_at = $now;
        }

        $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
        if ($groupLayer) {
            $this->timerService->startGroupTimer($ticket, $groupLayer, $now);
        }
        $ttrMetric->save();
    }

    protected function handlePauseToEnd(
        Ticket $ticket,
        TicketFirstResponseMetric $rtMetric,
        TicketStatusMetric $statusMetric,
        string $oldStatus,
        string $newStatus,
        Carbon $now,
        TicketEvent $event
    ): void {
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $waitingDuration = $this->timerService->getLastWaitingDuration($statusMetric, $oldStatus, $now);
        $isFirstEnd = !$ticket->resolved_at && !$ticket->closed_at;

        $this->timerService->accumulateWaitingTime($statusMetric, $oldStatus, $now);

        if ($ttrMetric->processing_mode !== 'due-driven') {
            if ($ttrMetric->latest_due_date_ttr) {
                $ttrMetric->latest_due_date_ttr = Carbon::parse($ttrMetric->latest_due_date_ttr)->addSeconds($waitingDuration);
            }
            if (!$rtMetric->hasFirstResponse() && $rtMetric->latest_due_date_rt) {
                $rtMetric->latest_due_date_rt = Carbon::parse($rtMetric->latest_due_date_rt)->addSeconds($waitingDuration);
            }
        }

        if (!$rtMetric->hasFirstResponse()) {
            $rtMetric->status = 'ended_closed_no_reply';
        }

        if ($isFirstEnd) {
            $this->timerService->finalizeTtrUsedTime($ticket, $statusMetric, $now);
        }

        $this->recordFirstEndTimestamp($ticket, $newStatus, $now);
        $this->timerService->finalizeResolutionTime($ticket, $statusMetric);
        $ttrMetric->save();
        $this->finalizeOpenStageOnTicketEnd($ticket, $now, $event);
    }

    protected function handleEndToRun(
        Ticket $ticket,
        TicketStatusMetric $statusMetric,
        string $oldStatus,
        Carbon $now,
        TicketEvent $event
    ): void {
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $closedDuration = 0;
        $endStartedAt = $this->findCurrentEndStartedAt($ticket, $event)
            ?? $this->fallbackEndStartedAt($ticket, $oldStatus);
        if ($endStartedAt) {
            $closedDuration = max(0, $now->timestamp - $endStartedAt->timestamp);
            $statusMetric->end_total_seconds += $closedDuration;
        }

        if ($ttrMetric->processing_mode !== 'due-driven') {
            if ($ttrMetric->latest_due_date_ttr) {
                $ttrMetric->latest_due_date_ttr = Carbon::parse($ttrMetric->latest_due_date_ttr)->addSeconds($closedDuration);
            }
        }

        $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
        if ($groupLayer) {
            $this->timerService->startGroupTimer($ticket, $groupLayer, $now);
        }
        $ttrMetric->save();
    }

    protected function handleEndToEnd(
        Ticket $ticket,
        TicketStatusMetric $statusMetric,
        string $newStatus,
        Carbon $now
    ): void {
        $this->recordFirstEndTimestamp($ticket, $newStatus, $now);
        $this->timerService->finalizeResolutionTime($ticket, $statusMetric);
    }

    protected function findCurrentEndStartedAt(Ticket $ticket, TicketEvent $currentEvent): ?Carbon
    {
        $priorEvents = TicketEvent::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('event_type', TicketEvent::EVENT_STATUS_CHANGED)
            ->where(function ($query) use ($currentEvent) {
                $query->where('event_timestamp', '<', $currentEvent->event_timestamp)
                    ->orWhere(function ($sameTimestamp) use ($currentEvent) {
                        $sameTimestamp->where('event_timestamp', $currentEvent->event_timestamp)
                            ->where('id', '<', $currentEvent->id);
                    });
            })
            ->orderByDesc('event_timestamp')
            ->orderByDesc('id')
            ->get();

        foreach ($priorEvents as $priorEvent) {
            $statusChange = collect($priorEvent->getFieldChanges())->firstWhere('field', 'status');
            if (!$statusChange) {
                continue;
            }

            $oldStatus = $this->timerService->canonicalizeStatus($statusChange['old_value'] ?? null);
            $newStatus = $this->timerService->canonicalizeStatus($statusChange['new_value'] ?? null);

            if (!$this->timerService->isEndStatus($oldStatus) && $this->timerService->isEndStatus($newStatus)) {
                return Carbon::parse($priorEvent->event_timestamp);
            }

            // A previous End -> Run boundary means there is no matching open End interval further back.
            if ($this->timerService->isEndStatus($oldStatus) && $this->timerService->isRunStatus($newStatus)) {
                break;
            }
        }

        return null;
    }

    protected function fallbackEndStartedAt(Ticket $ticket, string $oldStatus): ?Carbon
    {
        $fallback = $oldStatus === 'Closed' ? $ticket->closed_at : $ticket->resolved_at;

        return $fallback ? Carbon::parse($fallback) : null;
    }

    protected function recordFirstEndTimestamp(Ticket $ticket, string $status, Carbon $at): void
    {
        if ($status === 'Resolved' && !$ticket->resolved_at) {
            $ticket->resolved_at = $at;
        }

        if ($status === 'Closed' && !$ticket->closed_at) {
            $ticket->closed_at = $at;
        }
    }

    protected function handlePauseToPause(
        TicketStatusMetric $statusMetric,
        string $oldStatus,
        string $newStatus,
        Carbon $now
    ): void {
        $this->timerService->accumulateWaitingTime($statusMetric, $oldStatus, $now);

        if ($newStatus === 'Waiting For Customer') {
            $statusMetric->waiting_started_at = $now;
        } elseif ($newStatus === 'Pending') {
            $statusMetric->pending_started_at = $now;
        }
    }

    protected function finalizeOpenStageOnTicketEnd(Ticket $ticket, Carbon $endedAt, TicketEvent $event): void
    {
        $this->stageService->checkpointOpenStage($ticket, $event, $endedAt, 'ticket_closed');
    }
}
