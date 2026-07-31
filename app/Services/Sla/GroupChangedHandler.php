<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * GroupChangedHandler — Xử lý sự kiện thay đổi Group.
 *
 * Đặc tả 2.2.4:
 * - Dừng timer group cũ → bắt đầu timer group mới
 * - Group CX → bỏ qua đếm thời gian (làm mờ FE-app)
 * - Quản lý mở/đóng phiên ticket_group_sessions
 * - Tính toán lũy kế tổng thời gian xử lý thực tế của từng Layer (L1–L4) và từng Group
 * - Ghi log sự kiện vào timeline
 */
class GroupChangedHandler
{
    protected TimerService $timerService;
    protected SlaInitializationService $initService;
    protected TimelineService $timelineService;
    protected FreshdeskApiService $freshdeskService;

    public function __construct(
        TimerService $timerService,
        SlaInitializationService $initService,
        TimelineService $timelineService,
        FreshdeskApiService $freshdeskService
    ) {
        $this->timerService = $timerService;
        $this->initService = $initService;
        $this->timelineService = $timelineService;
        $this->freshdeskService = $freshdeskService;
    }

    /**
     * Xử lý sự kiện group_changed.
     */
    public function handle(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();

        $timestamp = !empty($event->event_data['ticket_data']['updated_at'])
            ? Carbon::parse($event->event_data['ticket_data']['updated_at'])
            : (
                $event->event_timestamp
                    ? Carbon::parse($event->event_timestamp)
                    : now()
            );

        $this->initService->ensureSlaInitialized($ticket, $timestamp);

        $groupIdChange = collect($changes)->firstWhere('field', 'group_id');
        $groupNameChange = collect($changes)->firstWhere('field', 'group_name');

        if (!$groupIdChange && !$groupNameChange) {
            Log::warning("GroupChangedHandler: Không tìm thấy thay đổi group trong mảng changes", ['ticket_id' => $ticketId]);
            return;
        }

        $oldGroupId = $groupIdChange['old_value'] ?? $ticket->group_id ?? null;
        if (!$oldGroupId && $groupNameChange) {
            $oldGroupId = $this->freshdeskService->resolveGroupId($groupNameChange['old_value'] ?? null);
        }

        $newGroupId = $groupIdChange['new_value'] ?? $ticketData['group_id'] ?? null;
        if (!$newGroupId && $groupNameChange) {
            $newGroupId = $this->freshdeskService->resolveGroupId(
                $groupNameChange['new_value'] ?? ($ticketData['group_name'] ?? null)
            );
        }

        $oldGroupName = $groupNameChange['old_value'] ?? $this->freshdeskService->resolveGroupName($oldGroupId);
        $newGroupName = $ticketData['group_name']
            ?? ($groupNameChange['new_value'] ?? $this->freshdeskService->resolveGroupName($newGroupId));

        $oldLayer = $this->timerService->getGroupLayer($oldGroupId, is_string($oldGroupName) ? $oldGroupName : null);
        $newLayer = $this->timerService->getGroupLayer($newGroupId, is_string($newGroupName) ? $newGroupName : null);

        Log::info("GroupChangedHandler: {$oldGroupName} → {$newGroupName}", [
            'ticket_id'        => $ticketId,
            'old_group_id'     => $oldGroupId,
            'new_group_id'     => $newGroupId,
            'old_layer'        => $oldLayer,
            'new_layer'        => $newLayer,
            'event_at'         => $timestamp->toIso8601String(),
        ]);

        $this->timerService->stopAllActiveGroupTimers($ticket, $timestamp);

        if ($newGroupId) {
            $ticket->group_id = $newGroupId;
        }

        if ($newLayer && $this->timerService->isRunStatus($ticket->status)) {
            $this->timerService->startGroupTimer($ticket, $newLayer, $timestamp);
        }

        $this->timerService->recalculateGroupMetrics($ticket, [], $timestamp);

        $ticket->save();

        if (!empty($newGroupName)) {
            $this->timelineService->appendTicketEventLog($ticket, 'g', $newGroupName, $event->event_timestamp);
        }

        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        if ($ttrMetric->latest_due_date_ttr) {
            $this->timelineService->appendTicketEventLog($ticket, 'd', $ttrMetric->latest_due_date_ttr->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
        }
        if ($rtMetric->latest_due_date_rt) {
            $this->timelineService->appendTicketEventLog($ticket, 'fr', $rtMetric->latest_due_date_rt->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
        }
    }
}
