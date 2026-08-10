<?php

namespace App\Services\Sla;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Calculates and stores Release 2 priority stages without applying them to
 * Freshdesk-facing live SLA metrics.
 */
class PriorityChangedHandler
{
    public function __construct(
        private readonly SlaInitializationService $initService,
        private readonly TimelineService $timelineService,
        private readonly SlaStageService $stageService
    ) {
    }

    public function handle(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        $priorityChange = collect($changes)->firstWhere('field', 'priority');
        if (!$priorityChange) {
            Log::warning('PriorityChangedHandler: priority change is missing', [
                'ticket_id' => $ticketId,
                'event_id' => $event->id,
            ]);
            return;
        }

        if (TicketSlaStage::query()
            ->where('ticket_id', $ticketId)
            ->where('trigger_type', 'priority_change')
            ->where('opened_by_event_id', $event->id)
            ->exists()) {
            return;
        }

        $ticket = Ticket::query()->where('ticket_id', $ticketId)->firstOrFail();
        $oldPriority = $this->initService->normalizePriority(
            $priorityChange['old_value'] ?? $ticket->priority
        );
        $newPriority = $this->initService->normalizePriority(
            $priorityChange['new_value'] ?? ($ticketData['priority'] ?? $ticket->priority)
        );

        if ($oldPriority === $newPriority) {
            return;
        }

        $this->initService->ensureSlaInitialized($ticket);

        $policy = SlaPolicy::getPolicy((string) $ticket->ticket_type, $newPriority);
        if (!$policy) {
            Log::warning('PriorityChangedHandler: SLA policy is missing', [
                'ticket_id' => $ticketId,
                'priority' => $newPriority,
            ]);
            return;
        }

        $changedAt = $this->resolveChangedAt($event);
        $checkpointedStage = $this->stageService->checkpointOpenStage(
            $ticket,
            $event,
            $changedAt,
            'priority_changed'
        );

        $ttr = $ticket->getOrCreateTtrMetric();
        $rt = $ticket->getOrCreateFirstResponseMetric();
        $processingMode = $ttr->processing_mode === 'due-driven'
            ? 'due-driven'
            : 'priority-driven';

        $stage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $policy->id,
            'sequence_number' => ((int) $ticket->slaStages()->max('sequence_number')) + 1,
            'priority_stage_number' => ((int) $ticket->slaStages()->max('priority_stage_number')) + 1,
            'trigger_type' => 'priority_change',
            'priority' => $newPriority,
            'processing_mode' => $processingMode,
            'opened_at' => $changedAt,
            'opened_by_event_id' => $event->id,
        ]);

        if ($processingMode === 'due-driven') {
            $this->storeDueDrivenMetrics($stage, $ticket, $policy);
        } else {
            $direction = $this->priorityDirection($oldPriority, $newPriority);
            $this->storeCalculatedMetric(
                $stage,
                $ticket,
                $checkpointedStage,
                'ttr',
                (int) $policy->total_seconds,
                (int) $ttr->used_seconds,
                (int) $ttr->total_seconds,
                $ttr->latest_due_date_ttr ? Carbon::parse($ttr->latest_due_date_ttr) : null,
                $direction,
                $changedAt
            );

            if ($rt->hasFirstResponse() || in_array($rt->status, ['ended_replied', 'ended_closed_no_reply'], true)) {
                TicketSlaStageMetric::create([
                    'ticket_sla_stage_id' => $stage->id,
                    'metric_type' => 'rt',
                    'sla_goal_seconds' => (int) $policy->rt_seconds,
                    'used_before_seconds' => (int) $rt->used_seconds,
                    'effective_sla_seconds' => 0,
                    'extra_time_granted_seconds' => 0,
                    'eligibility_status' => 'not_applicable',
                    'old_due_at' => $rt->latest_due_date_rt,
                    'standard_due_at' => $rt->latest_due_date_rt,
                    'adjusted_due_at' => $rt->latest_due_date_rt,
                    'metric_result' => 'not_applicable',
                    'result_reason' => 'first_response_already_completed',
                ]);
            } else {
                $this->storeCalculatedMetric(
                    $stage,
                    $ticket,
                    $checkpointedStage,
                    'rt',
                    (int) $policy->rt_seconds,
                    (int) $rt->used_seconds,
                    (int) $rt->total_seconds,
                    $rt->latest_due_date_rt ? Carbon::parse($rt->latest_due_date_rt) : null,
                    $direction,
                    $changedAt
                );
            }
        }

        $ticket->priority = $newPriority;
        $ticket->save();

        $this->timelineService->appendTicketEventLog(
            $ticket,
            'p',
            $newPriority,
            $changedAt,
            null,
            $event
        );

        Log::info('PriorityChangedHandler: Release 2 calculation stored locally', [
            'ticket_id' => $ticketId,
            'event_id' => $event->id,
            'old_priority' => $oldPriority,
            'new_priority' => $newPriority,
            'processing_mode' => $processingMode,
        ]);
    }

    private function storeCalculatedMetric(
        TicketSlaStage $stage,
        Ticket $ticket,
        ?TicketSlaStage $checkpointedStage,
        string $metricType,
        int $newGoal,
        int $usedNow,
        int $liveTotal,
        ?Carbon $liveDue,
        string $direction,
        Carbon $changedAt
    ): void {
        $previousMetric = $checkpointedStage?->metrics->firstWhere('metric_type', $metricType);
        $previousRemaining = $previousMetric
            ? max(
                0,
                (int) $previousMetric->effective_sla_seconds
                    - max(0, $usedNow - (int) $previousMetric->used_before_seconds)
            )
            : max(0, $liveTotal - $usedNow);

        $failedMetric = $this->firstFailedMetric($ticket, $metricType);
        $wasFailed = $failedMetric !== null;
        $baseRemaining = max(0, $newGoal - $usedNow);
        $extraTime = 0;
        $eligibility = 'not_applicable';
        $reason = $direction . '_priority';

        if ($direction === 'upgrade') {
            $eligibility = $wasFailed || $previousRemaining === 0 ? 'ineligible' : 'eligible';
            if ($eligibility === 'eligible') {
                $extraTime = min($previousRemaining, intdiv($newGoal, 2));
                $reason = 'upgrade_extra_time_eligible';
            } else {
                $reason = $wasFailed
                    ? 'overdue_before_priority_change'
                    : 'no_remaining_time_before_priority_change';
            }
        } elseif ($direction === 'downgrade' && $wasFailed) {
            $reason = 'overdue_before_priority_change';
        }

        $standardDue = $changedAt->copy()->addSeconds($baseRemaining);
        $adjustedDue = $wasFailed
            ? ($failedMetric->adjusted_due_at
                ? Carbon::parse($failedMetric->adjusted_due_at)
                : ($liveDue?->copy() ?? $standardDue->copy()))
            : $changedAt->copy()->addSeconds($baseRemaining + $extraTime);

        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => $metricType,
            'sla_goal_seconds' => $newGoal,
            'used_before_seconds' => max(0, $usedNow),
            'effective_sla_seconds' => $wasFailed ? 0 : $baseRemaining + $extraTime,
            'extra_time_granted_seconds' => $extraTime,
            'eligibility_status' => $eligibility,
            'old_due_at' => $previousMetric?->adjusted_due_at ?? $liveDue,
            'standard_due_at' => $standardDue,
            'adjusted_due_at' => $adjustedDue,
            'metric_result' => $wasFailed ? 'fail' : 'pending',
            'result_reason' => $reason,
            'overdue_at' => $wasFailed ? $failedMetric->overdue_at : null,
            'overdue_owner_group_id' => $wasFailed ? $failedMetric->overdue_owner_group_id : null,
        ]);
    }

    private function storeDueDrivenMetrics(TicketSlaStage $stage, Ticket $ticket, SlaPolicy $policy): void
    {
        $ttr = $ticket->getOrCreateTtrMetric();
        $rt = $ticket->getOrCreateFirstResponseMetric();

        foreach ([
            [
                'type' => 'ttr',
                'goal' => (int) $policy->total_seconds,
                'used' => (int) $ttr->used_seconds,
                'effective' => max(0, (int) $ttr->total_seconds - (int) $ttr->used_seconds),
                'due' => $ttr->latest_due_date_ttr,
                'result' => 'pending',
                'reason' => 'due_driven_priority_is_informational',
            ],
            [
                'type' => 'rt',
                'goal' => (int) $policy->rt_seconds,
                'used' => (int) $rt->used_seconds,
                'effective' => max(0, (int) $rt->total_seconds - (int) $rt->used_seconds),
                'due' => $rt->latest_due_date_rt,
                'result' => $rt->hasFirstResponse() ? 'not_applicable' : 'pending',
                'reason' => $rt->hasFirstResponse()
                    ? 'first_response_already_completed'
                    : 'due_driven_priority_is_informational',
            ],
        ] as $metric) {
            TicketSlaStageMetric::create([
                'ticket_sla_stage_id' => $stage->id,
                'metric_type' => $metric['type'],
                'sla_goal_seconds' => $metric['goal'],
                'used_before_seconds' => $metric['used'],
                'effective_sla_seconds' => $metric['effective'],
                'extra_time_granted_seconds' => 0,
                'eligibility_status' => 'not_applicable',
                'old_due_at' => $metric['due'],
                'standard_due_at' => $metric['due'],
                'adjusted_due_at' => $metric['due'],
                'metric_result' => $metric['result'],
                'result_reason' => $metric['reason'],
            ]);
        }
    }

    private function firstFailedMetric(Ticket $ticket, string $metricType): ?TicketSlaStageMetric
    {
        return TicketSlaStageMetric::query()
            ->whereHas('stage', fn ($query) => $query->where('ticket_id', $ticket->ticket_id))
            ->where('metric_type', $metricType)
            ->where('metric_result', 'fail')
            ->orderBy('overdue_at')
            ->orderBy('id')
            ->first();
    }

    private function priorityDirection(string $oldPriority, string $newPriority): string
    {
        $weights = config('freshdesk.priority_weight', []);
        $oldWeight = (int) ($weights[$oldPriority] ?? 0);
        $newWeight = (int) ($weights[$newPriority] ?? 0);

        return $newWeight > $oldWeight
            ? 'upgrade'
            : ($newWeight < $oldWeight ? 'downgrade' : 'unchanged');
    }

    private function resolveChangedAt(TicketEvent $event): Carbon
    {
        $timestamp = $event->event_timestamp
            ?? ($event->event_data['ticket_data']['updated_at'] ?? null);

        return $timestamp ? Carbon::parse($timestamp) : now();
    }
}
