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

        foreach ($stage->metrics as $metric) {
            if (in_array($metric->metric_result, ['fail', 'not_applicable'], true)) {
                continue;
            }

            $dueAt = $metric->adjusted_due_at;
            $evaluationAt = $metric->metric_type === 'rt' && $rtMetric->first_response_at
                ? Carbon::parse($rtMetric->first_response_at)
                : $checkpointAt;
            $failed = $dueAt && $evaluationAt->greaterThan($dueAt);

            $metric->update([
                'used_at_checkpoint_seconds' => $metric->metric_type === 'ttr'
                    ? $ttrMetric->used_seconds
                    : $rtMetric->used_seconds,
                'metric_result' => $dueAt ? ($failed ? 'fail' : 'pass') : 'not_applicable',
                'result_reason' => $dueAt
                    ? $context.($failed ? '_after_due' : '_before_due')
                    : 'due_date_not_available',
                'overdue_at' => $failed ? $dueAt : null,
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
