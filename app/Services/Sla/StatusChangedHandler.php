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

        $oldStatus = $statusChange['old_value'] ?? $ticket->status;
        $newStatus = $statusChange['new_value'] ?? $ticketData['status'];

        Log::info("StatusChangedHandler: {$oldStatus} → {$newStatus}", ['ticket_id' => $ticketId]);

        $ticket->status = $newStatus;

        $this->recalculateSlaOnStatusChange($ticket, $oldStatus, $newStatus, $eventUpdatedAt, $event);

        $ticket->save();

        $this->timelineService->appendTicketEventLog($ticket, 's', $this->timerService->getShortStatus($newStatus), $event->event_timestamp);

        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        if ($ttrMetric->latest_due_date_ttr) {
            $this->timelineService->appendTicketEventLog($ticket, 'd', $ttrMetric->latest_due_date_ttr->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
        }
        if ($rtMetric->latest_due_date_rt) {
            $this->timelineService->appendTicketEventLog($ticket, 'fr', $rtMetric->latest_due_date_rt->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
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
            $this->handleEndToRun($ticket, $rtMetric, $statusMetric, $now);
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

        if (!$rtMetric->hasFirstResponse()) {
            $this->timerService->accumulateRtUsedTime($rtMetric, $now);
            $rtMetric->status = 'ended_closed_no_reply';
        }

        if ($newStatus === 'Resolved') {
            $ticket->resolved_at = $now;
        }
        $ticket->closed_at = $now;

        $this->timerService->finalizeResolutionTime($statusMetric, $now);
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
        } else {
            $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
            if ($groupLayer) {
                $aggregateTimer = $ticket->getOrCreateGroupMetric($groupLayer, null);
                $aggregateTimer->used_seconds = max(0, (int)$aggregateTimer->used_seconds + $waitingDuration);
                $aggregateTimer->save();

                if ($ticket->group_id) {
                    $subTimer = $ticket->getOrCreateGroupMetric($groupLayer, $ticket->group_id);
                    $subTimer->used_seconds = max(0, (int)$subTimer->used_seconds + $waitingDuration);
                    $subTimer->save();
                }
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

        $this->timerService->accumulateWaitingTime($statusMetric, $oldStatus, $now);

        if ($ttrMetric->processing_mode !== 'due-driven') {
            if ($ttrMetric->latest_due_date_ttr) {
                $ttrMetric->latest_due_date_ttr = Carbon::parse($ttrMetric->latest_due_date_ttr)->addSeconds($waitingDuration);
            }
            if (!$rtMetric->hasFirstResponse() && $rtMetric->latest_due_date_rt) {
                $rtMetric->latest_due_date_rt = Carbon::parse($rtMetric->latest_due_date_rt)->addSeconds($waitingDuration);
            }
        } else {
            $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
            if ($groupLayer) {
                $aggregateTimer = $ticket->getOrCreateGroupMetric($groupLayer, null);
                $aggregateTimer->used_seconds = max(0, (int)$aggregateTimer->used_seconds + $waitingDuration);
                $aggregateTimer->save();

                if ($ticket->group_id) {
                    $subTimer = $ticket->getOrCreateGroupMetric($groupLayer, $ticket->group_id);
                    $subTimer->used_seconds = max(0, (int)$subTimer->used_seconds + $waitingDuration);
                    $subTimer->save();
                }
            }
        }

        if (!$rtMetric->hasFirstResponse()) {
            $rtMetric->status = 'ended_closed_no_reply';
        }

        if ($newStatus === 'Resolved') {
            $ticket->resolved_at = $now;
        }
        $ticket->closed_at = $now;

        $this->timerService->finalizeResolutionTime($statusMetric, $now);
        $ttrMetric->save();
        $this->finalizeOpenStageOnTicketEnd($ticket, $now, $event);
    }

    protected function handleEndToRun(
        Ticket $ticket,
        TicketFirstResponseMetric $rtMetric,
        TicketStatusMetric $statusMetric,
        Carbon $now
    ): void {
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $closedDuration = 0;
        if ($ticket->closed_at) {
            $closedDuration = abs($now->timestamp - Carbon::parse($ticket->closed_at)->timestamp);
            $statusMetric->end_total_seconds += $closedDuration;
        }

        $ticket->resolved_at = null;
        $statusMetric->resolution_started_at = $now;

        if ($ttrMetric->processing_mode === 'due-driven') {
            $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
            if ($groupLayer) {
                $aggregateTimer = $ticket->getOrCreateGroupMetric($groupLayer, null);
                $aggregateTimer->used_seconds = max(0, (int)$aggregateTimer->used_seconds + $closedDuration);
                $aggregateTimer->save();

                if ($ticket->group_id) {
                    $subTimer = $ticket->getOrCreateGroupMetric($groupLayer, $ticket->group_id);
                    $subTimer->used_seconds = max(0, (int)$subTimer->used_seconds + $closedDuration);
                    $subTimer->save();
                }
            }
        } else {
            if ($ttrMetric->latest_due_date_ttr) {
                $ttrMetric->latest_due_date_ttr = Carbon::parse($ttrMetric->latest_due_date_ttr)->addSeconds($closedDuration);
            }
        }

        if (!$rtMetric->hasFirstResponse()) {
            if ($ttrMetric->processing_mode !== 'due-driven' && $rtMetric->latest_due_date_rt) {
                $rtMetric->latest_due_date_rt = Carbon::parse($rtMetric->latest_due_date_rt)->addSeconds($closedDuration);
            }
            $rtMetric->status = 'running';
            $rtMetric->started_at = $now;
        }

        $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
        if ($groupLayer) {
            $this->timerService->startGroupTimer($ticket, $groupLayer, $now);
        }
        $ttrMetric->save();
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
