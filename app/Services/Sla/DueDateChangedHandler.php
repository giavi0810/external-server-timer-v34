<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Models\FreshdeskOutboundOperation;
use App\Models\SlaPolicy;
use App\Models\TicketDueDateChange;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Models\TicketTtrMetric;
use App\Models\TicketFirstResponseMetric;
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
        // 0. Idempotency check: tránh xử lý lặp lại cùng một event
        if ($event->id && TicketSlaStage::query()
            ->where('ticket_id', $ticketId)
            ->where('trigger_type', 'due_date_change')
            ->where('opened_by_event_id', $event->id)
            ->exists()) {
            Log::info("DueDateChangedHandler: bỏ qua vì event due_date_change đã được xử lý", [
                'ticket_id' => $ticketId,
                'event_id' => $event->id,
            ]);
            return;
        }

        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();

        $eventAt = $event->occurredAt();
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
        $dueChanged = !$oldDue || !$oldDue->equalTo($newDue);

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

        $customFields = $event->event_data['ticket_data']['custom_fields'] ?? [];
        $incomingProcessingMode = $this->customFieldByPrefix(
            $customFields,
            ['cf_processing_mode', 'cf_sla_mode']
        );

        $ttrMetric->processing_mode = ($incomingProcessingMode === 'priority-driven')
            ? 'priority-driven'
            : 'due-driven';
        $ttrMetric->save();
        $rtMetric->save();

        $calculationPolicy = $this->resolvePolicyBeforeFirstDueDate($ticket, $event, $eventAt, $ticketData);
        $this->recalculateSlaOnDueDateChange($ticket, $oldDue, $newDue, $eventAt, $calculationPolicy, $ttrMetric, $rtMetric);

        $ticket->save();
        if ($dueChanged) {
            $this->recordDueDateStage(
                $ticket,
                $event,
                $oldDue,
                $newDue,
                $eventAt,
                $customFields,
                $ttrMetric->processing_mode,
                $calculationPolicy,
                $ttrMetric,
                $rtMetric
            );
        } else {
            Log::info('DueDateChangedHandler: bỏ qua stage vì due_by không đổi', [
                'ticket_id' => $ticketId,
                'due_by' => $newDue->toIso8601String(),
            ]);
        }

        if ($dueChanged) {
            $this->timelineService->appendTicketEventLog($ticket, 'd', $newDue->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp, null, $event);
        }

        if ($rtMetric->latest_due_date_rt) {
            $this->timelineService->appendTicketEventLog($ticket, 'fr', $rtMetric->latest_due_date_rt->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp, null, $event);
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
        Carbon $eventAt,
        array $customFields,
        string $processingMode,
        ?SlaPolicy $calculationPolicy = null,
        ?TicketTtrMetric $ttrMetric = null,
        ?TicketFirstResponseMetric $rtMetric = null
    ): void {
        $policy = $calculationPolicy
            ?? SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $ticket->priority);
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
            'priority' => $policy->priority,
            'processing_mode' => $processingMode,
            'opened_at' => $eventAt,
            'opened_by_event_id' => $event->id,
        ]);

        $ttr = $ttrMetric ?? $ticket->getOrCreateTtrMetric();
        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => 'ttr',
            'sla_goal_seconds' => $policy->total_seconds,
            'used_before_seconds' => $ttr->used_seconds,
            'effective_sla_seconds' => $ttr->total_seconds,
            'old_due_at' => $oldDue,
            'standard_due_at' => Carbon::parse($ticket->fd_created_at)->addSeconds($policy->total_seconds),
            'adjusted_due_at' => $newDue,
        ]);

        $rt = $rtMetric ?? $ticket->getOrCreateFirstResponseMetric();
        $isRtCompleted = $rt->hasFirstResponse() || in_array($rt->status, ['ended_replied', 'ended_closed_no_reply'], true);
        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => 'rt',
            'sla_goal_seconds' => $policy->rt_seconds,
            'used_before_seconds' => $rt->used_seconds,
            'effective_sla_seconds' => $rt->total_seconds,
            'old_due_at' => $rt->latest_due_date_rt,
            'standard_due_at' => $rt->original_due_date_rt,
            'adjusted_due_at' => $rt->latest_due_date_rt,
            'metric_result' => $isRtCompleted ? 'not_applicable' : 'pending',
            'result_reason' => $isRtCompleted ? 'first_response_already_completed' : null,
        ]);

        $agentId = (int) ($event->event_data['conversation_data']['actor_id'] ?? 0);
        $agentName = (string) ($event->event_data['conversation_data']['actor_name'] ?? '');

        if ($agentName === '' || $agentName === 'System') {
            $recentOp = FreshdeskOutboundOperation::query()
                ->where('ticket_id', $ticket->ticket_id)
                ->where('operation_type', 'change_due_date')
                ->where('created_at', '<=', $eventAt->copy()->addMinutes(10))
                ->latest('created_at')
                ->first()
                ?? FreshdeskOutboundOperation::query()
                    ->where('ticket_id', $ticket->ticket_id)
                    ->where('operation_type', 'change_due_date')
                    ->latest('created_at')
                    ->first();

            if ($recentOp && !empty($recentOp->payload['agent_name'])) {
                $agentName = (string) $recentOp->payload['agent_name'];
                if ($agentId === 0 && !empty($recentOp->payload['agent_id'])) {
                    $agentId = (int) $recentOp->payload['agent_id'];
                }
            }
        }

        if ($agentName === '') {
            $agentName = 'System';
        }

        TicketDueDateChange::create([
            'ticket_id' => $ticket->ticket_id,
            'ticket_sla_stage_id' => $stage->id,
            'change_number' => ((int) $ticket->dueDateChanges()->max('change_number')) + 1,
            'old_due_at' => $oldDue,
            'new_due_at' => $newDue,
            'processing_phase' => $this->customFieldByPrefix(
                $customFields,
                ['cf_processing_phase']
            ) ?? 'unspecified',
            'reason_code' => $this->customFieldByPrefix(
                $customFields,
                ['cf_change_due_reason']
            ) ?? 'unspecified',
            'reason_detail' => null,
            'agent_id' => $agentId,
            'agent_name' => $agentName,
            'submitted_at' => $eventAt,
        ]);
    }

    protected function recalculateSlaOnDueDateChange(
        Ticket $ticket,
        ?Carbon $oldDue,
        Carbon $newDue,
        ?Carbon $eventAt = null,
        ?SlaPolicy $calculationPolicy = null,
        ?TicketTtrMetric $ttrMetric = null,
        ?TicketFirstResponseMetric $rtMetric = null
    ): void {
        $ttrMetric ??= $ticket->getOrCreateTtrMetric();
        if (!$ticket->fd_created_at || !$ttrMetric->latest_due_date_ttr) {
            return;
        }

        $config = $calculationPolicy
            ?? SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $ticket->priority);

        if (!$config) return;

        if ($ttrMetric->processing_mode === 'due-driven' && $oldDue) {
            $diffSeconds = $newDue->timestamp - $oldDue->timestamp;
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

            $ttrMetric->total_seconds = max(0, $newDue->timestamp - $createdAt->timestamp - $pauseTime);
        }

        $ttrMetric->save();

        $this->timerService->recalculateGroupMetrics(
            $ticket,
            $this->fitAnchoredGroupBudgets($config, (int) $ttrMetric->total_seconds),
            $eventAt
        );

        $rtMetric ??= $ticket->getOrCreateFirstResponseMetric();
        if (!$rtMetric->hasFirstResponse() && !in_array($rtMetric->status, ['ended_replied', 'ended_closed_no_reply'], true)) {
            $rtMetric->total_seconds = $config->rt_seconds;
            $this->timerService->recalculateRtMetrics($rtMetric);
            $rtMetric->save();

            Log::info("DueDateChangedHandler: Cập nhật rt_total theo config mới", [
                'ticket_id' => $ticket->ticket_id,
                'rt_total'  => $config->rt_seconds,
            ]);
        }
    }

    /**
     * Resolve the anchor SLA policy for Due Date changes:
     * 1. In due-driven mode, preserve the policy anchored by the first due_date_change stage.
     * 2. If first due_date_change:
     *    - Priority from event Due Date snapshot (no DB query if present).
     *    - Fallback 1: closest priority_changed event where event_timestamp < eventAt.
     *    - Fallback 2: priority from ticket_created event.
     *    - Fallback 3: $ticket->priority with warning log.
     *    - Determine SlaPolicy (preserving version from active prior stage if same priority).
     */
    public function resolvePolicyBeforeFirstDueDate(
        Ticket $ticket,
        TicketEvent $event,
        Carbon $eventAt,
        array $ticketData = []
    ): ?SlaPolicy {
        // Priority-driven tickets must follow the current priority policy. Only
        // due-driven tickets keep the policy anchored by the first Due Date change.
        if ($ticket->getOrCreateTtrMetric()->processing_mode === 'due-driven') {
            $firstDueDatePolicyId = TicketSlaStage::query()
                ->where('ticket_id', $ticket->ticket_id)
                ->where('trigger_type', 'due_date_change')
                ->where('processing_mode', 'due-driven')
                ->orderBy('sequence_number')
                ->value('sla_policy_id');

            if ($firstDueDatePolicyId) {
                $policy = SlaPolicy::find($firstDueDatePolicyId);
                if ($policy) {
                    return $policy;
                }
            }
        }

        // 2. Lấy Priority tại thời điểm ngay trước Change Due Date theo thứ tự:
        // 2.1. Priority trong snapshot của chính event Due Date (không query lịch sử nếu có)
        $snapshotPriority = $ticketData['priority']
            ?? ($event->event_data['ticket_data']['priority'] ?? null);

        $resolvedPriority = null;
        if ($snapshotPriority !== null && $snapshotPriority !== '') {
            $resolvedPriority = $this->normalizePriority($snapshotPriority);
        }

        // 2.2. Nếu snapshot thiếu, lấy event Priority gần nhất có event_timestamp < thời gian Change Due Date
        if ($resolvedPriority === null) {
            $lastPriorityEvent = TicketEvent::query()
                ->where('ticket_id', $ticket->ticket_id)
                ->where('event_type', TicketEvent::EVENT_PRIORITY_CHANGED)
                ->where('event_timestamp', '<', $eventAt)
                ->orderByDesc('event_timestamp')
                ->orderByDesc('id')
                ->first();

            if ($lastPriorityEvent) {
                $priorityChange = collect($lastPriorityEvent->field_changes)->firstWhere('field', 'priority');
                $pri = $priorityChange['new_value']
                    ?? ($lastPriorityEvent->event_data['ticket_data']['priority'] ?? null);
                if ($pri !== null && $pri !== '') {
                    $resolvedPriority = $this->normalizePriority($pri);
                }
            }
        }

        // 2.3. Nếu chưa từng đổi Priority, lấy Priority từ ticket_created
        if ($resolvedPriority === null) {
            $createdEvent = TicketEvent::query()
                ->where('ticket_id', $ticket->ticket_id)
                ->where('event_type', TicketEvent::EVENT_TICKET_CREATED)
                ->orderBy('event_timestamp')
                ->orderBy('id')
                ->first();

            if ($createdEvent) {
                $pri = $createdEvent->event_data['ticket_data']['priority'] ?? null;
                if ($pri !== null && $pri !== '') {
                    $resolvedPriority = $this->normalizePriority($pri);
                }
            }
        }

        // 2.4. Chỉ fallback về $ticket->priority khi toàn bộ lịch sử thiếu dữ liệu và phải ghi warning log
        if ($resolvedPriority === null) {
            Log::warning("DueDateChangedHandler: Toàn bộ lịch sử thiếu dữ liệu Priority, fallback về ticket->priority", [
                'ticket_id' => $ticket->ticket_id,
                'fallback_priority' => $ticket->priority,
            ]);
            $resolvedPriority = $this->normalizePriority($ticket->priority);
        }

        // 3. Xác định SlaPolicy theo Priority vừa tìm được
        // Ưu tiên giữ đúng version từ stage trước đó của ticket nếu có cùng priority
        $policy = null;
        $lastStageBeforeDue = TicketSlaStage::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('opened_at', '<=', $eventAt)
            ->orderByDesc('sequence_number')
            ->first();

        if ($lastStageBeforeDue && $lastStageBeforeDue->priority === $resolvedPriority && $lastStageBeforeDue->sla_policy_id) {
            $policy = SlaPolicy::find($lastStageBeforeDue->sla_policy_id);
        }

        if (!$policy) {
            $policy = SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $resolvedPriority);
        }

        return $policy;
    }

    private function normalizePriority(mixed $priority): string
    {
        $map = [
            1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Urgent',
            '1' => 'Low', '2' => 'Medium', '3' => 'High', '4' => 'Urgent',
        ];

        return $map[$priority] ?? (is_string($priority) && $priority !== '' ? $priority : 'Low');
    }

    /**
     * Keep the anchor policy allocation and apply all Due Date delta to L4.
     * If a Due Date is shortened below the anchor SLA, shrink L4 first, then
     * L3, L2 and L1 so Group totals always equal the current TTR total.
     *
     * @return array<string, int>
     */
    private function fitAnchoredGroupBudgets(SlaPolicy $policy, int $targetTotal): array
    {
        $budgets = [
            'L1' => max(0, (int) $policy->l1_seconds),
            'L2' => max(0, (int) $policy->l2_seconds),
            'L3' => max(0, (int) $policy->l3_seconds),
            'L4' => max(0, (int) $policy->l4_seconds),
        ];
        $delta = max(0, $targetTotal) - array_sum($budgets);

        if ($delta >= 0) {
            $budgets['L4'] += $delta;

            return $budgets;
        }

        $remainingCut = abs($delta);
        foreach (['L4', 'L3', 'L2', 'L1'] as $layer) {
            $cut = min($remainingCut, $budgets[$layer]);
            $budgets[$layer] -= $cut;
            $remainingCut -= $cut;

            if ($remainingCut === 0) {
                break;
            }
        }

        return $budgets;
    }

    private function customFieldByPrefix(array $customFields, array $prefixes): mixed
    {
        foreach ($customFields as $key => $value) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $key, $prefix)) {
                    return $value;
                }
            }
        }

        return null;
    }
}
