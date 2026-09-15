<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketEvent;

use App\Models\TicketGroupSession;
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

            $overdueAt = $failed ? ($effectiveDueAt ?? $dueAt ?? $checkpointAt) : null;
            $overdueOwnerGroupId = $failed
                ? $this->resolveOverdueOwnerGroupId($ticket, $overdueAt, $metric->overdue_owner_group_id)
                : null;

            $metric->update([
                'used_at_checkpoint_seconds' => $usedSeconds,
                'metric_result' => ($dueAt || $effectiveSla > 0) ? ($failed ? 'fail' : 'pass') : 'not_applicable',
                'result_reason' => ($dueAt || $effectiveSla > 0)
                    ? $context . ($failed ? '_after_due' : '_before_due')
                    : 'due_date_not_available',
                'overdue_at' => $overdueAt,
                'overdue_owner_group_id' => $overdueOwnerGroupId,
            ]);
        }

        $stage->update([
            'checkpoint_at' => $checkpointAt,
            'checkpoint_event_id' => $event->id,
        ]);

        return $stage;
    }

    /**
     * Xác định Đơn vị chịu trách nhiệm vi phạm (Overdue Owner) theo BR-GRP-05.
     * Giá trị mang tính lịch sử bất biến và không bị thay đổi ngay cả khi ticket chuyển group sau đó.
     */
    protected function resolveOverdueOwnerGroupId(
        Ticket $ticket,
        ?Carbon $overdueAt,
        ?string $existingOwnerGroupId
    ): ?string {
        if ($existingOwnerGroupId !== null && $existingOwnerGroupId !== '') {
            return $existingOwnerGroupId;
        }

        if ($overdueAt) {
            $session = TicketGroupSession::query()
                ->where('ticket_id', $ticket->ticket_id)
                ->where('from_time', '<=', $overdueAt)
                ->where(function ($query) use ($overdueAt) {
                    $query->whereNull('to_time')
                        ->orWhere('to_time', '>=', $overdueAt);
                })
                ->latest('from_time')
                ->first();

            if ($session && $session->group_id) {
                return (string) $session->group_id;
            }
        }

        return $ticket->group_id ? (string) $ticket->group_id : null;
    }
}
