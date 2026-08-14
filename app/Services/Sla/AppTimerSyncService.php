<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketFirstResponseMetric;
use App\Models\TicketGroupMetric;
use App\Models\TicketTtrMetric;
use App\Services\FreshdeskApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AppTimerSyncService
{
    protected FreshdeskApiService $freshdeskService;
    protected TimelineService $timelineService;

    public function __construct(FreshdeskApiService $freshdeskService, TimelineService $timelineService)
    {
        $this->freshdeskService = $freshdeskService;
        $this->timelineService = $timelineService;
    }

    /**
     * Sync ticket SLA metrics to Freshdesk custom field 'cf_timer_history'.
     */
    public function syncTicket(Ticket $ticket): void
    {
        $data = $this->generateCompactJson($ticket);
        $jsonString = json_encode($data);

        // Build SLA custom fields payload and Activity Log
        $activityLogString = $this->timelineService->getTimelineString($ticket->ticket_id);

        $customFields = array_merge(
            [
                'cf_timer_history' => $jsonString,
                'cf_activity_log' => $activityLogString,
            ],
            $this->buildSlaCustomFields($ticket)
        );

        $payload = [
            'custom_fields' => $customFields,
        ];

        $synced = $this->freshdeskService->updateTicket($ticket->ticket_id, $payload);

        if (!$synced) {
            $apiErrorContext = $this->freshdeskService->getLastErrorContext() ?? [];
            Log::error('SLA Timer sync to Freshdesk failed', array_merge([
                'ticket_id' => $ticket->ticket_id,
                'json_size' => strlen($jsonString),
                'phase' => 'sync_to_freshdesk',
            ], $apiErrorContext));

            $reason = $apiErrorContext['reason'] ?? ($apiErrorContext['status'] ?? ($apiErrorContext['error'] ?? 'unknown'));
            $status = $apiErrorContext['status'] ?? null;
            $reasonDetail = $status !== null ? "{$reason}, status={$status}" : (string) $reason;
            $retryAfter = $apiErrorContext['retry_after'] ?? null;
            $retryAfterStr = $retryAfter ? ", retry_after={$retryAfter}" : "";
            throw new \RuntimeException("Freshdesk sync failed for ticket {$ticket->ticket_id} (reason: {$reasonDetail}{$retryAfterStr})");
        }

        $ticket->touch();

        Log::info("SLA Timer synced to Freshdesk", [
            'ticket_id' => $ticket->ticket_id,
            'json_size' => strlen($jsonString),
        ]);
    }

    /**
     * Generate the compact JSON format for UI.
     * All durations are converted from seconds to milliseconds.
     */
    protected function generateCompactJson(Ticket $ticket): array
    {
        $ticket->loadMissing(['groupMetrics', 'firstResponseMetric', 'statusMetric', 'slaStages.metrics']);

        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $statusMetric = $ticket->getOrCreateStatusMetric();

        $subGroupIds = $ticket->groupMetrics
            ->whereNotNull('group_id')
            ->pluck('group_id')
            ->filter()
            ->unique()
            ->values();

        $groupNameMap = $subGroupIds->isEmpty()
            ? collect()
            : FreshdeskGroup::query()
                ->whereIn('group_id', $subGroupIds)
                ->pluck('name', 'group_id');

        // 1. Group Timers (vcx = L1, fo = L2, bo = L3, po = L4)
        $groupData = [];
        $layersMap = [
            'L1' => 'vcx',
            'L2' => 'fo',
            'L3' => 'bo',
            'L4' => 'po',
        ];

        foreach ($layersMap as $layer => $alias) {
            // Aggregate record (Tong Layer - group_id IS NULL)
            $timer = $ticket->groupMetrics->where('layer', $layer)->whereNull('group_id')->first();
            if ($timer) {
                $groupData[$alias] = $this->formatTimer($timer);
            }

            // Sub-group records (Chi tiet tung Group con trong Layer)
            $subTimers = $ticket->groupMetrics->where('layer', $layer)->whereNotNull('group_id');
            if ($subTimers->isNotEmpty()) {
                $subMap = [];
                foreach ($subTimers as $st) {
                    $groupName = $groupNameMap->get($st->group_id, (string) $st->group_id);
                    $subMap[$groupName] = [
                        'bu' => max(0, (int) $st->used_seconds) * 1000,
                        'ls' => $st->started_at ? $st->started_at->toIso8601ZuluString() : null,
                    ];
                }
                $groupData[$alias . '_g'] = $subMap;
            }
        }

        // Release 1 keeps Priority/Extra Time calculations in the database only.
        // These keys stay compatible with the current app payload but must not
        // expose calculated Release 2 values to Freshdesk yet.
        $extraInfo = [
            'sg' => null,
            'et' => null,
            'pu' => null,
            'pc' => null,
            'dn' => null,
            'rsg' => null,
            'ret' => null,
            'rpu' => null,
            'rfr' => null,
        ];

        $currentResoSeconds = max(0, (int) ($statusMetric->resolution_total_seconds ?? 0));

        return [
            'db' => $ttrMetric->latest_due_date_ttr?->toIso8601ZuluString(),
            'fb' => $rtMetric->latest_due_date_rt?->toIso8601ZuluString(),
            'gr' => $groupData,
            'wt' => [
                'tw' => max(0, $statusMetric->waiting_total_seconds ?? 0) * 1000,
                'ws' => $statusMetric->waiting_started_at ? $statusMetric->waiting_started_at->toIso8601ZuluString() : null,
            ],
            'pt' => [
                'tp' => max(0, $statusMetric->pending_total_seconds ?? 0) * 1000,
                'ps' => $statusMetric->pending_started_at ? $statusMetric->pending_started_at->toIso8601ZuluString() : null,
            ],
            'rt' => [
                'at' => max(0, $rtMetric->total_seconds ?? 0) * 1000,
                'us' => max(0, (int) $rtMetric->used_seconds) * 1000,
                'rs' => $rtMetric->started_at ? $rtMetric->started_at->toIso8601ZuluString() : null,
            ],
            'rs' => [
                'us' => $currentResoSeconds * 1000,
                'rs' => $statusMetric->resolution_started_at ? $statusMetric->resolution_started_at->toIso8601ZuluString() : null,
            ],
            'ttr' => max(0, $ttrMetric->total_seconds ?? 0) * 1000,
            'et' => $extraInfo,
        ];
    }

    /**
     * Format timer model to array in milliseconds.
     */
    protected function formatTimer(TicketGroupMetric $timer): array
    {
        $used = max(0, (int) $timer->used_seconds);
        $total = max(0, (int) $timer->total_seconds);

        return [
            'at' => $total * 1000,
            'bu' => $used * 1000,
            'ot' => max(0, $used - $total) * 1000,
            'rt' => max(0, $total - $used) * 1000,
            'ls' => $timer->started_at ? $timer->started_at->toIso8601ZuluString() : null,
        ];
    }

    /**
     * Build SLA custom fields for Freshdesk.
     */
    protected function buildSlaCustomFields(Ticket $ticket): array
    {
        $ticket->loadMissing('groupMetrics');
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        $ttrMetric = $ticket->getOrCreateTtrMetric();

        $rtUsed = $this->effectiveRtUsed($rtMetric);
        $rtTotal = max(0, (int) $rtMetric->total_seconds);
        $rtOverdue = $rtUsed > $rtTotal
            || (!$rtMetric->hasFirstResponse()
                && $rtMetric->latest_due_date_rt
                && now()->greaterThan($rtMetric->latest_due_date_rt));
        $rtDiff = $rtTotal - $rtUsed;

        $ttrUsed = $this->effectiveTtrUsed($ticket, $ttrMetric);
        $ttrTotal = max(0, (int) $ttrMetric->total_seconds);
        $closedAfterDue = $ticket->closed_at
            && $ttrMetric->latest_due_date_ttr
            && $ticket->closed_at->greaterThan($ttrMetric->latest_due_date_ttr);
        $openRunningAfterDue = !$ticket->closed_at
            && $ticket->isRunning()
            && $ttrMetric->latest_due_date_ttr
            && now()->greaterThan($ttrMetric->latest_due_date_ttr);
        $ttrOverdue = $ttrUsed > $ttrTotal || $closedAfterDue || $openRunningAfterDue;
        $ttrDiff = $ttrTotal - $ttrUsed;

        $fields = [
            'cf_rt_time' => ($rtDiff < 0 ? '-' : '') . $this->formatDuration(abs($rtDiff)),
            'cf_rt_overdue' => $rtOverdue ? 'Yes' : 'No',
            'cf_ttr_time' => ($ttrDiff < 0 ? '-' : '') . $this->formatDuration(abs($ttrDiff)),
            'cf_ttr_overdue' => $ttrOverdue ? 'Yes' : 'No',
            'cf_processing_mode' => $ttrMetric->processing_mode,
        ];

        $firstFailedMetric = $ticket->slaStages()
            ->whereHas('metrics', fn ($query) => $query
                ->where('metric_result', 'fail')
                ->whereNotNull('overdue_at'))
            ->with([
                'metrics' => fn ($query) => $query
                    ->where('metric_result', 'fail')
                    ->whereNotNull('overdue_at')
                    ->orderBy('overdue_at'),
            ])
            ->orderBy('sequence_number')
            ->first();

        if ($firstFailedMetric) {
            $failedMetric = $firstFailedMetric->metrics->first();
            $fields['cf_fail_time'] = $failedMetric->overdue_at->toIso8601ZuluString();
            $fields['cf_fail_stage'] = 'Stage ' . $firstFailedMetric->sequence_number;
            $fields['cf_fail_flow'] = $firstFailedMetric->processing_mode;
        }

        $layerFields = ['L1' => 'l1', 'L2' => 'l2', 'L3' => 'l3', 'L4' => 'l4'];
        foreach ($layerFields as $layer => $prefix) {
            $timer = $ticket->groupMetrics->where('layer', $layer)->whereNull('group_id')->first();
            $total = $timer ? max(0, (int) $timer->total_seconds) : 0;
            $used = $timer ? $this->effectiveGroupUsed($timer) : 0;
            $violated = $used > $total;

            $fields["cf__{$prefix}_time_allowed"] = $this->formatDuration($total);
            $fields["cf__{$prefix}_time_actual"] = $this->formatDuration($used);
            $fields["cf__{$prefix}_violated"] = $violated ? 'Yes' : 'No';
        }

        return $fields;
    }

    protected function effectiveRtUsed(TicketFirstResponseMetric $metric): int
    {
        $used = max(0, (int) $metric->used_seconds);
        if ($metric->status === 'running' && $metric->started_at) {
            $used += max(0, now()->timestamp - $metric->started_at->timestamp);
        }

        return $used;
    }

    protected function effectiveGroupUsed(TicketGroupMetric $metric): int
    {
        $used = max(0, (int) $metric->used_seconds);
        if ($metric->started_at) {
            $used += max(0, now()->timestamp - $metric->started_at->timestamp);
        }

        return $used;
    }

    protected function effectiveTtrUsed(Ticket $ticket, TicketTtrMetric $metric): int
    {
        $groupUsed = $ticket->groupMetrics
            ->whereNull('group_id')
            ->sum(fn (TicketGroupMetric $groupMetric) => $this->effectiveGroupUsed($groupMetric));

        return max((int) $metric->used_seconds, (int) $groupUsed);
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $months = intdiv($seconds, 2419200);
        $seconds %= 2419200;
        $weeks = intdiv($seconds, 604800);
        $seconds %= 604800;
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($months > 0)
            $parts[] = "{$months}M";
        if ($weeks > 0)
            $parts[] = "{$weeks}w";
        if ($days > 0)
            $parts[] = "{$days}d";
        if ($hours > 0)
            $parts[] = "{$hours}h";
        if ($minutes > 0)
            $parts[] = "{$minutes}m";
        if ($secs > 0)
            $parts[] = "{$secs}s";

        return implode(' ', $parts) ?: '0s';
    }
}
