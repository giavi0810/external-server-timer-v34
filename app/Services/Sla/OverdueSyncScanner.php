<?php

namespace App\Services\Sla;

use App\Models\TicketFirstResponseMetric;
use App\Models\TicketTtrMetric;
use App\Services\Queue\FreshdeskOutboundService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OverdueSyncScanner
{
    public function __construct(
        private readonly FreshdeskOutboundService $outboundService
    ) {}

    /**
     * Enqueue at most $limit syncs for each SLA metric type.
     *
     * @return array{ttr: int, rt: int}
     */
    public function scan(int $limit = 500, ?Carbon $at = null): array
    {
        $at ??= now();
        $limit = max(1, $limit);

        $result = [
            'ttr' => $this->scanTtr($at, $limit),
            'rt' => $this->scanFirstResponse($at, $limit),
        ];

        Log::info('SLA overdue scan completed', [
            'scanned_at' => $at->toIso8601String(),
            'ttr_dispatched' => $result['ttr'],
            'rt_dispatched' => $result['rt'],
            'limit_per_metric' => $limit,
        ]);

        return $result;
    }

    private function scanTtr(Carbon $at, int $limit): int
    {
        $query = TicketTtrMetric::query()
            ->whereNotNull('latest_due_date_ttr')
            ->where('latest_due_date_ttr', '<', $at)
            ->whereHas('ticket', fn ($query) => $query
                ->whereIn('status', config('freshdesk.run_statuses', [])));
        $this->excludeAlreadyQueued(
            $query,
            'ticket_ttr_metrics',
            'latest_due_date_ttr',
            'ttr'
        );
        $metrics = $query
            ->orderBy('latest_due_date_ttr')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($metrics as $candidate) {
            $didDispatch = DB::transaction(function () use ($candidate, $at): bool {
                $metric = TicketTtrMetric::query()
                    ->with('ticket')
                    ->lockForUpdate()
                    ->find($candidate->ticket_id);
                if (! $metric || ! $metric->ticket || ! $metric->latest_due_date_ttr) {
                    return false;
                }

                $dueAt = Carbon::parse($metric->latest_due_date_ttr);
                if (
                    ! $dueAt->lessThan($at)
                    || ! $metric->ticket->isRunning()
                ) {
                    return false;
                }

                return $this->enqueueSync($metric->ticket_id, 'ttr', $dueAt);
            });

            $dispatched += $didDispatch ? 1 : 0;
        }

        return $dispatched;
    }

    private function scanFirstResponse(Carbon $at, int $limit): int
    {
        $query = TicketFirstResponseMetric::query()
            ->where('status', 'running')
            ->whereNull('first_response_at')
            ->whereNotNull('latest_due_date_rt')
            ->where('latest_due_date_rt', '<', $at)
            ->whereHas('ticket', fn ($query) => $query
                ->whereIn('status', config('freshdesk.run_statuses', [])));
        $this->excludeAlreadyQueued(
            $query,
            'ticket_first_response_metrics',
            'latest_due_date_rt',
            'rt'
        );
        $metrics = $query
            ->orderBy('latest_due_date_rt')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($metrics as $candidate) {
            $didDispatch = DB::transaction(function () use ($candidate, $at): bool {
                $metric = TicketFirstResponseMetric::query()
                    ->with('ticket')
                    ->lockForUpdate()
                    ->find($candidate->ticket_id);
                if (! $metric || ! $metric->ticket || ! $metric->latest_due_date_rt) {
                    return false;
                }

                $dueAt = Carbon::parse($metric->latest_due_date_rt);
                if (
                    ! $dueAt->lessThan($at)
                    || $metric->status !== 'running'
                    || $metric->hasFirstResponse()
                    || ! $metric->ticket->isRunning()
                ) {
                    return false;
                }

                return $this->enqueueSync($metric->ticket_id, 'rt', $dueAt);
            });

            $dispatched += $didDispatch ? 1 : 0;
        }

        return $dispatched;
    }

    private function enqueueSync(int $ticketId, string $metricType, Carbon $dueAt): bool
    {
        $dueKey = $dueAt->copy()->utc()->format('Ymd\THis.u\Z');

        $operation = $this->outboundService->enqueueCommand(
            $ticketId,
            'sync_sla',
            "sla-overdue:{$metricType}:{$ticketId}:{$dueKey}",
            [
                'source' => 'sla-overdue-scanner',
                'metric_type' => $metricType,
                'due_at' => $dueAt->toIso8601ZuluString(),
            ]
        );

        return $operation->wasRecentlyCreated;
    }

    private function excludeAlreadyQueued(
        Builder $query,
        string $metricTable,
        string $dueColumn,
        string $metricType
    ): void {
        $driver = DB::getDriverName();

        $query->whereNotExists(function ($operationQuery) use (
            $driver,
            $metricTable,
            $dueColumn,
            $metricType
        ): void {
            $operationQuery
                ->selectRaw('1')
                ->from('freshdesk_outbound_operations as overdue_operations')
                ->whereColumn('overdue_operations.ticket_id', "{$metricTable}.ticket_id")
                ->where('overdue_operations.operation_type', 'sync_sla');

            if ($driver === 'pgsql') {
                $operationQuery
                    ->whereRaw("overdue_operations.payload->>'source' = ?", ['sla-overdue-scanner'])
                    ->whereRaw("overdue_operations.payload->>'metric_type' = ?", [$metricType])
                    ->whereRaw(
                        "(overdue_operations.payload->>'due_at')::timestamptz = {$metricTable}.{$dueColumn}"
                    );

                return;
            }

            if ($driver === 'sqlite') {
                $operationQuery
                    ->whereRaw("json_extract(overdue_operations.payload, '$.source') = ?", ['sla-overdue-scanner'])
                    ->whereRaw("json_extract(overdue_operations.payload, '$.metric_type') = ?", [$metricType])
                    ->whereRaw(
                        "datetime(json_extract(overdue_operations.payload, '$.due_at')) = datetime({$metricTable}.{$dueColumn})"
                    );

                return;
            }

            // The unique idempotency key remains the final protection on other
            // database drivers, even when JSON comparison is unavailable here.
            $operationQuery->whereRaw('1 = 0');
        });
    }
}
