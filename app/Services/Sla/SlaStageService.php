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

        if (!$stage) {
            return null;
        }

        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();

        $statusMetric = $ticket->getOrCreateStatusMetric();
        $pauseSeconds = (int) (($statusMetric->waiting_total_seconds ?? 0) + ($statusMetric->pending_total_seconds ?? 0));
        if ($statusMetric->waiting_started_at && $checkpointAt->greaterThan($statusMetric->waiting_started_at)) {
            $pauseSeconds += $checkpointAt->timestamp - Carbon::parse($statusMetric->waiting_started_at)->timestamp;
        }
        if ($statusMetric->pending_started_at && $checkpointAt->greaterThan($statusMetric->pending_started_at)) {
            $pauseSeconds += $checkpointAt->timestamp - Carbon::parse($statusMetric->pending_started_at)->timestamp;
        }

        $isDueDriven = $stage->processing_mode === 'due-driven';

        foreach ($stage->metrics as $metric) {
            if (in_array($metric->metric_result, ['fail', 'not_applicable'], true)) {
                continue;
            }

            $dueAt = $metric->adjusted_due_at;
            $evaluationAt = $metric->metric_type === 'rt' && $rtMetric->first_response_at
                ? Carbon::parse($rtMetric->first_response_at)
                : $checkpointAt;

            $usedSeconds = $metric->metric_type === 'ttr'
                ? (int) $ttrMetric->used_seconds
                : (int) $rtMetric->used_seconds;

            $effectiveSla = (int) ($metric->effective_sla_seconds > 0
                ? $metric->effective_sla_seconds
                : $metric->sla_goal_seconds);

            $effectiveDueAt = $dueAt ? Carbon::parse($dueAt)->addSeconds($pauseSeconds) : null;

            if ($isDueDriven && $metric->metric_type === 'ttr') {
                $failed = $dueAt && $evaluationAt->greaterThan($dueAt);
            } else {
                // Tuân thủ BR-EVL-01 (CAL-STAGE-RESULT & CAL-RT-RESULT):
                // 1. Quá hạn ngân sách SLA: used_seconds > effective_sla (used = goal thì chưa overdue)
                // 2. HOẶC quá hạn thời gian lịch sau khi đã bù thời gian Pause: evaluationAt > effectiveDueAt
                $exceededSla = $effectiveSla > 0 && $usedSeconds > $effectiveSla;
                $exceededDue = $effectiveDueAt && $evaluationAt->greaterThan($effectiveDueAt);

                $failed = $exceededSla || $exceededDue;
            }

            $metric->update([
                'used_at_checkpoint_seconds' => $usedSeconds,
                'metric_result' => ($dueAt || $effectiveSla > 0) ? ($failed ? 'fail' : 'pass') : 'not_applicable',
                'result_reason' => ($dueAt || $effectiveSla > 0)
                    ? $context.($failed ? '_after_due' : '_before_due')
                    : 'due_date_not_available',
                'overdue_at' => $failed ? ($effectiveDueAt ?? $dueAt ?? $checkpointAt) : null,
                'overdue_owner_group_id' => $failed ? $ticket->group_id : null,
            ]);
        }

        $stage->update([
            'checkpoint_at' => $checkpointAt,
            'checkpoint_event_id' => $event->id,
        ]);

        return $stage;
    }
}
