<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaStage;
use Carbon\Carbon;

class SlaStageService
{
    public function checkpointOpenStage(
        Ticket $ticket,
        TicketEvent $event,
        Carbon $checkpointAt,
        string $context
    ): ?TicketSlaStage {
        $stage = $ticket->slaStages()
            ->whereNull('checkpoint_at')
            ->with('metrics')
            ->latest('sequence_number')
            ->first();

        if (! $stage) {
            return null;
        }

        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();

        $isDueDriven = $stage->processing_mode === 'due-driven';
        $pauseSecondsByEvaluationAt = [];

        foreach ($stage->metrics as $metric) {
            if (in_array($metric->metric_result, ['fail', 'not_applicable'], true)) {
                continue;
            }

            $dueAt = $metric->adjusted_due_at;
            $evaluationAt = $metric->metric_type === 'rt' && $rtMetric->first_response_at
                ? Carbon::parse($rtMetric->first_response_at)
                : $checkpointAt;
            $evaluationKey = $evaluationAt->utc()->format('Y-m-d H:i:s.u');
            $pauseSeconds = $pauseSecondsByEvaluationAt[$evaluationKey]
                ??= $this->pauseSecondsDuringStage($ticket, $stage, $event, $evaluationAt);

            $usedSeconds = $metric->metric_type === 'ttr'
                ? (int) $ttrMetric->used_seconds
                : (int) $rtMetric->used_seconds;

            $effectiveSla = (int) ($metric->effective_sla_seconds > 0
                ? $metric->effective_sla_seconds
                : $metric->sla_goal_seconds);

            $effectiveDueAt = $dueAt ? Carbon::parse($dueAt)->addSeconds($pauseSeconds) : null;

            if ($isDueDriven && $metric->metric_type === 'ttr') {
                $failed = $dueAt && $evaluationAt->greaterThan($dueAt);
                $overdueAt = $dueAt;
            } else {
                // Tuân thủ BR-EVL-01 (CAL-STAGE-RESULT & CAL-RT-RESULT):
                // 1. Quá hạn ngân sách SLA: used_seconds > effective_sla (used = goal thì chưa overdue)
                // 2. HOẶC quá hạn thời gian lịch sau khi đã bù thời gian Pause: evaluationAt > effectiveDueAt
                $exceededSla = $effectiveSla > 0 && $usedSeconds > $effectiveSla;
                $exceededDue = $effectiveDueAt && $evaluationAt->greaterThan($effectiveDueAt);

                $failed = $exceededSla || $exceededDue;
                $overdueAt = $effectiveDueAt ?? $dueAt ?? $checkpointAt;
            }

            $metric->update([
                'used_at_checkpoint_seconds' => $usedSeconds,
                'metric_result' => ($dueAt || $effectiveSla > 0) ? ($failed ? 'fail' : 'pass') : 'not_applicable',
                'result_reason' => ($dueAt || $effectiveSla > 0)
                    ? $context.($failed ? '_after_due' : '_before_due')
                    : 'due_date_not_available',
                'overdue_at' => $failed ? $overdueAt : null,
                'overdue_owner_group_id' => $failed ? $ticket->group_id : null,
            ]);
        }

        $stage->update([
            'checkpoint_at' => $checkpointAt,
            'checkpoint_event_id' => $event->id,
        ]);

        return $stage;
    }

    private function pauseSecondsDuringStage(
        Ticket $ticket,
        TicketSlaStage $stage,
        TicketEvent $checkpointEvent,
        Carbon $checkpointAt
    ): int {
        $openedAt = Carbon::parse($stage->opened_at);
        if ($checkpointAt->lessThanOrEqualTo($openedAt)) {
            return 0;
        }

        $openedEvent = $stage->openedByEvent()->first();
        $statusEvents = TicketEvent::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('event_type', TicketEvent::EVENT_STATUS_CHANGED)
            ->whereBetween('event_timestamp', [$openedAt, $checkpointAt])
            ->orderBy('event_timestamp')
            ->orderBy('source_order_key')
            ->orderBy('id')
            ->get()
            ->filter(fn (TicketEvent $statusEvent): bool => (! $openedEvent || $this->compareEventOrder($statusEvent, $openedEvent) > 0)
                && $this->compareEventOrder($statusEvent, $checkpointEvent) <= 0
            )
            ->values();

        $status = data_get($openedEvent?->event_data, 'ticket_data.status');
        if (! $status && $statusEvents->isNotEmpty()) {
            $firstChange = collect($statusEvents->first()->getFieldChanges())
                ->firstWhere('field', 'status');
            $status = $firstChange['old_value'] ?? null;
        }
        $status ??= $ticket->status;

        $pauseSeconds = 0;
        $cursor = $openedAt;

        foreach ($statusEvents as $statusEvent) {
            $changedAt = Carbon::parse($statusEvent->event_timestamp);
            if ($changedAt->lessThan($cursor)) {
                continue;
            }

            if ($this->isPauseStatus($status)) {
                $pauseSeconds += max(0, $changedAt->timestamp - $cursor->timestamp);
            }

            $change = collect($statusEvent->getFieldChanges())->firstWhere('field', 'status');
            $status = $change['new_value'] ?? $status;
            $cursor = $changedAt;
        }

        if ($this->isPauseStatus($status)) {
            $pauseSeconds += max(0, $checkpointAt->timestamp - $cursor->timestamp);
        }

        return $pauseSeconds;
    }

    private function compareEventOrder(TicketEvent $left, TicketEvent $right): int
    {
        $timestampComparison = strcmp(
            Carbon::parse($left->event_timestamp)->utc()->format('Y-m-d H:i:s.u'),
            Carbon::parse($right->event_timestamp)->utc()->format('Y-m-d H:i:s.u')
        );
        if ($timestampComparison !== 0) {
            return $timestampComparison;
        }

        $sourceComparison = strcmp(
            (string) ($left->source_order_key ?? ''),
            (string) ($right->source_order_key ?? '')
        );

        return $sourceComparison !== 0
            ? $sourceComparison
            : ((int) $left->id <=> (int) $right->id);
    }

    private function isPauseStatus(mixed $status): bool
    {
        return in_array((string) $status, config('freshdesk.pause_statuses', []), true);
    }
}
