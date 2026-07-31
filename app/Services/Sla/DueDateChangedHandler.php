<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Models\SlaPolicy;
use App\Models\TicketDueDateChange;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * DueDateChangedHandler — Xử lý sự kiện thay đổi Due Date.
 *
 * Đặc tả 2.2.3 (Change due date):
 * Hai chế độ:
 * 1. Agent thay đổi trên Freshdesk → cập nhật L4 với thời gian tăng thêm
 * 2. Submit từ App Timer → TTR = SLA_priority + (new_due_app - old_due_immediate)
 *
 * Sau change due date:
 * - Đánh dấu processing_mode = due-driven
 * - Lưu original_due_date và change_due_date_app
 * - Phân bổ thời gian tăng thêm vào L4
 * - Pause → Run: KHÔNG cộng waiting time vào due date
 */
class DueDateChangedHandler
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

    /**
     * Xử lý sự kiện due_date_changed.
     */
    public function handle(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();

        $eventAt = $event->event_timestamp ? Carbon::parse($event->event_timestamp) : now();
        $this->initService->ensureSlaInitialized($ticket);
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();

        $ticketTypeChange = collect($changes)->firstWhere('field', 'ticket_type');
        $newTicketType = $ticketTypeChange['new_value'] ?? null;
        if (is_string($newTicketType) && $newTicketType !== '' && $ticket->ticket_type !== $newTicketType) {
            Log::info("DueDateChangedHandler: ticket_type thay đổi", [
                'ticket_id' => $ticketId,
                'old_type' => $ticket->ticket_type,
                'new_type' => $newTicketType,
            ]);
            $ticket->ticket_type = $newTicketType;
        }

        $oldDue = $ttrMetric->latest_due_date_ttr ? Carbon::parse($ttrMetric->latest_due_date_ttr) : null;
        $newDueRaw = $ticketData['due_by']
            ?? (collect($changes)->firstWhere('field', 'due_by')['new_value'] ?? null);
        if (!$newDueRaw) {
            Log::warning("DueDateChangedHandler: thiếu giá trị due_by mới", ['ticket_id' => $ticketId]);
            return;
        }
        $newDue = Carbon::parse($newDueRaw);

        $newFrDueRaw = $ticketData['fr_due_by']
            ?? ($ticketData['frDueBy'] ?? null)
            ?? (collect($changes)->firstWhere('field', 'fr_due_by')['new_value'] ?? null)
            ?? (collect($changes)->firstWhere('field', 'frDueBy')['new_value'] ?? null);
        $newFrDue = $newFrDueRaw ? Carbon::parse($newFrDueRaw) : null;

        Log::info("DueDateChangedHandler: due_by thay đổi", [
            'ticket_id' => $ticketId,
            'old_due'   => $oldDue?->toIso8601String(),
            'new_due'   => $newDue->toIso8601String(),
        ]);

        if (!$ttrMetric->original_due_date_ttr) {
            $ttrMetric->original_due_date_ttr = $ttrMetric->latest_due_date_ttr;
        }

        $ttrMetric->latest_due_date_ttr = $newDue;
        if ($newFrDue) {
            $rtMetric->latest_due_date_rt = $newFrDue;
        }

        $isAppChange = ($ttrMetric->processing_mode === 'due-driven') && ($oldDue && $newDue->greaterThan($oldDue));
        
        $ttrMetric->processing_mode = $isAppChange ? 'due-driven' : 'priority-driven';
        $ttrMetric->save();
        $rtMetric->save();

        $this->recalculateSlaOnDueDateChange($ticket, $oldDue, $eventAt);

        $ticket->save();
        $this->recordDueDateStage($ticket, $event, $oldDue, $newDue, $eventAt);

        $this->timelineService->appendTicketEventLog($ticket, 'd', $newDue->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
        
        if ($rtMetric->latest_due_date_rt) {
            $this->timelineService->appendTicketEventLog($ticket, 'fr', $rtMetric->latest_due_date_rt->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
        }

        Log::info("DueDateChangedHandler: hoàn thành", [
            'ticket_id'        => $ticketId,
            'original_due'     => $ttrMetric->original_due_date_ttr,
            'new_due'          => $newDue->toIso8601String(),
        ]);
    }

    protected function recordDueDateStage(
        Ticket $ticket,
        TicketEvent $event,
        ?Carbon $oldDue,
        Carbon $newDue,
        Carbon $eventAt
    ): void {
        $policy = SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $ticket->priority);
        if (!$policy || !$oldDue) {
            return;
        }

        $this->stageService->checkpointOpenStage($ticket, $event, $eventAt, 'due_date_changed');

        $stage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $policy->id,
            'sequence_number' => ((int) $ticket->slaStages()->max('sequence_number')) + 1,
            'priority_stage_number' => null,
            'trigger_type' => 'due_date_change',
            'priority' => $ticket->priority,
            'processing_mode' => 'due-driven',
            'opened_at' => $eventAt,
            'opened_by_event_id' => $event->id,
        ]);

        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => 'ttr',
            'sla_goal_seconds' => $policy->total_seconds,
            'used_before_seconds' => $ticket->getOrCreateTtrMetric()->used_seconds,
            'effective_sla_seconds' => $ticket->getOrCreateTtrMetric()->total_seconds,
            'old_due_at' => $oldDue,
            'standard_due_at' => Carbon::parse($ticket->fd_created_at)->addSeconds($policy->total_seconds),
            'adjusted_due_at' => $newDue,
        ]);

        $rt = $ticket->getOrCreateFirstResponseMetric();
        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => 'rt',
            'sla_goal_seconds' => $policy->rt_seconds,
            'used_before_seconds' => $rt->used_seconds,
            'effective_sla_seconds' => $rt->total_seconds,
            'old_due_at' => $rt->latest_due_date_rt,
            'standard_due_at' => $rt->original_due_date_rt,
            'adjusted_due_at' => $rt->latest_due_date_rt,
            'metric_result' => $rt->first_response_at ? 'not_applicable' : 'pending',
        ]);

        $customFields = $event->event_data['ticket_data']['custom_fields'] ?? [];
        TicketDueDateChange::create([
            'ticket_id' => $ticket->ticket_id,
            'ticket_sla_stage_id' => $stage->id,
            'change_number' => ((int) $ticket->dueDateChanges()->max('change_number')) + 1,
            'old_due_at' => $oldDue,
            'new_due_at' => $newDue,
            'processing_phase' => $customFields['cf_processing_phase'] ?? 'unspecified',
            'reason_code' => $customFields['cf_change_due_reason'] ?? 'unspecified',
            'reason_detail' => null,
            'agent_id' => (int) ($event->event_data['conversation_data']['actor_id'] ?? 0),
            'agent_name' => (string) ($event->event_data['conversation_data']['actor_name'] ?? 'System'),
            'submitted_at' => $eventAt,
        ]);
    }

    protected function recalculateSlaOnDueDateChange(Ticket $ticket, ?Carbon $oldDue, ?Carbon $eventAt = null): void
    {
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        if (!$ticket->fd_created_at || !$ttrMetric->latest_due_date_ttr) {
            return;
        }

        $newDue = Carbon::parse($ttrMetric->latest_due_date_ttr);
        $config = SlaPolicy::where('ticket_type', $ticket->ticket_type)
            ->where('priority', $ticket->priority)
            ->first();

        if (!$config) return;

        if ($ttrMetric->processing_mode === 'due-driven' && $oldDue) {
            $diffSeconds = $oldDue->diffInSeconds($newDue, false);
            $oldTotal = (int) $ttrMetric->total_seconds;
            $ttrMetric->total_seconds = max(0, $oldTotal + $diffSeconds);
            
            Log::info("DueDateChangedHandler: Cập nhật ttr_total theo công thức App", [
                'ticket_id' => $ticket->ticket_id,
                'old_ttr'   => $oldTotal,
                'diff'      => $diffSeconds,
                'new_ttr'   => $ttrMetric->total_seconds,
            ]);
        } else {
            $createdAt = Carbon::parse($ticket->fd_created_at);
            $statusMetric = $ticket->getOrCreateStatusMetric();
            $pauseTime = (int) $statusMetric->waiting_total_seconds + (int) $statusMetric->pending_total_seconds + (int) $statusMetric->end_total_seconds;
            
            $ttrMetric->total_seconds = max(0, $createdAt->diffInSeconds($newDue, false) - $pauseTime);
        }

        $ttrMetric->save();

        $extension = max(0, $ttrMetric->total_seconds - $config->total_seconds);

        $l4Budget = (int) $config->l4_seconds + $extension;
        $this->timerService->recalculateGroupMetrics($ticket, [
            'L4' => $l4Budget,
        ], $eventAt);

        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        if (!$rtMetric->hasFirstResponse() && !in_array($rtMetric->status, ['ended_replied', 'ended_closed_no_reply'])) {
            $rtMetric->total_seconds = $config->rt_seconds;
            $this->timerService->recalculateRtMetrics($rtMetric);
            $rtMetric->save();

            Log::info("DueDateChangedHandler: Cập nhật rt_total theo config mới", [
                'ticket_id' => $ticket->ticket_id,
                'rt_total'  => $config->rt_seconds,
            ]);
        }
    }
}
