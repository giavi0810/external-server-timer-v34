<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SlaComplianceService
{
    public function __construct(
        private readonly TimerService $timerService
    ) {}

    /**
     * Persist the ticket-level compliance state produced by one source event.
     * Dynamic overdue values are not stored; only sticky history and the latest
     * End-cycle result are persisted.
     *
     * @return array{rt: bool, ttr: bool}
     */
    public function captureEvent(TicketEvent $event): array
    {
        $ticket = Ticket::query()->where('ticket_id', $event->ticket_id)->firstOrFail();
        $at = $event->occurredAt();
        $enteringEnd = $this->isEnteringEnd($event);
        $rtOverdue = $this->currentRtOverdue($ticket, $at);
        $ttrOverdue = $enteringEnd
            ? $this->isTtrOverdue($ticket, $at, true)
            : $this->currentTtrOverdue($ticket, $at);

        $this->recordViolation($ticket, $rtOverdue, $ttrOverdue, $at);

        if ($enteringEnd) {
            $ticket->final_sla_compliant = ! $ttrOverdue;
            $ticket->save();
        }

        return ['rt' => $rtOverdue, 'ttr' => $ttrOverdue];
    }

    /** @return array{rt: bool, ttr: bool} */
    public function captureCurrent(Ticket $ticket, ?Carbon $at = null): array
    {
        $at ??= now();
        $rtOverdue = $this->currentRtOverdue($ticket, $at);
        $ttrOverdue = $this->currentTtrOverdue($ticket, $at);
        $this->recordViolation($ticket, $rtOverdue, $ttrOverdue, $at);

        return ['rt' => $rtOverdue, 'ttr' => $ttrOverdue];
    }

    public function recordScannerViolation(Ticket $ticket, string $metric, Carbon $at): void
    {
        $this->recordViolation(
            $ticket,
            $metric === 'rt',
            $metric === 'ttr',
            $at
        );
    }

    public function currentRtOverdue(Ticket $ticket, ?Carbon $at = null): bool
    {
        return $this->isRtOverdue($ticket, $this->rtEvaluationAt($ticket, $at ?? now()));
    }

    public function currentTtrOverdue(Ticket $ticket, ?Carbon $at = null): bool
    {
        if ($ticket->isEnded() && $ticket->final_sla_compliant !== null) {
            return ! (bool) $ticket->final_sla_compliant;
        }

        if ($ticket->isEnded()) {
            $endAt = $this->latestEndEntryAt($ticket);
            if ($endAt) {
                return $this->isTtrOverdue($ticket, $endAt, true);
            }
        }

        return $this->isTtrOverdue($ticket, $at ?? now());
    }

    public function isRtOverdue(Ticket $ticket, Carbon $at): bool
    {
        $metric = $ticket->getOrCreateFirstResponseMetric();
        $used = max(0, (int) $metric->used_seconds);

        if ($metric->status === 'running' && $metric->started_at) {
            $used += max(0, $at->timestamp - Carbon::parse($metric->started_at)->timestamp);
        }

        $exceededUsed = $used > max(0, (int) $metric->total_seconds);
        $exceededDue = $metric->latest_due_date_rt
            && $at->greaterThan(Carbon::parse($metric->latest_due_date_rt));

        return $exceededUsed || (! $metric->hasFirstResponse() && $exceededDue);
    }

    public function isTtrOverdue(Ticket $ticket, Carbon $at, bool $enteringEnd = false): bool
    {
        $metric = $ticket->getOrCreateTtrMetric();
        $statusMetric = $ticket->getOrCreateStatusMetric();
        $used = $ticket->isEnded() || $enteringEnd
            ? max(0, (int) $metric->used_seconds)
            : $this->timerService->calculateTtrUsedSeconds($ticket, $statusMetric, $at);
        $exceededUsed = $used > max(0, (int) $metric->total_seconds);

        // Final ticket compliance is evaluated by the rule of the active mode
        // at the first Run/Pause -> End boundary of each cycle.
        if ($enteringEnd) {
            if ($metric->processing_mode === 'due-driven') {
                return $metric->latest_due_date_ttr
                    && $at->greaterThan(Carbon::parse($metric->latest_due_date_ttr));
            }

            return $exceededUsed;
        }

        if ($metric->processing_mode === 'due-driven') {
            $exceededDue = $metric->latest_due_date_ttr
                && $at->greaterThan(Carbon::parse($metric->latest_due_date_ttr));

            return $exceededUsed || (bool) $exceededDue;
        }

        $deadlineActive = $ticket->isRunning() || $enteringEnd;
        $exceededDue = $deadlineActive
            && $metric->latest_due_date_ttr
            && $at->greaterThan(Carbon::parse($metric->latest_due_date_ttr));

        return $exceededUsed || (bool) $exceededDue;
    }

    private function recordViolation(
        Ticket $ticket,
        bool $rtOverdue,
        bool $ttrOverdue,
        Carbon $at
    ): void {
        if (! $rtOverdue && ! $ttrOverdue) {
            return;
        }

        $cause = $rtOverdue && $ttrOverdue
            ? 'both'
            : ($rtOverdue ? 'rt' : 'ttr');

        $locked = DB::transaction(function () use ($ticket, $at, $cause): Ticket {
            $locked = Ticket::query()
                ->where('ticket_id', $ticket->ticket_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->sla_violated) {
                $locked->sla_violated = true;
                $locked->sla_violated_at = $at;
                $locked->sla_violation_metric = $cause;
            } elseif (! $locked->sla_violation_metric) {
                $locked->sla_violated_at ??= $at;
                $locked->sla_violation_metric = $cause;
            } elseif ($locked->sla_violation_metric !== $cause
                && $locked->sla_violation_metric !== 'both') {
                $locked->sla_violation_metric = 'both';
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked;
        });

        $ticket->setRawAttributes($locked->getAttributes(), true);
    }

    private function rtEvaluationAt(Ticket $ticket, Carbon $fallback): Carbon
    {
        $metric = $ticket->getOrCreateFirstResponseMetric();
        if ($metric->first_response_at) {
            return Carbon::parse($metric->first_response_at);
        }

        if ($metric->status === 'ended_closed_no_reply') {
            $endAt = collect([$ticket->resolved_at, $ticket->closed_at])
                ->filter()
                ->map(fn ($value) => Carbon::parse($value))
                ->sortBy(fn (Carbon $value) => $value->timestamp)
                ->first();

            if ($endAt) {
                return $endAt;
            }
        }

        return $fallback;
    }

    private function latestEndEntryAt(Ticket $ticket): ?Carbon
    {
        $events = TicketEvent::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('event_type', TicketEvent::EVENT_STATUS_CHANGED)
            ->orderByDesc('event_timestamp')
            ->orderByDesc('source_order_key')
            ->orderByDesc('id')
            ->cursor(['id', 'field_changes', 'event_timestamp']);

        foreach ($events as $event) {
            $change = collect($event->getFieldChanges())->firstWhere('field', 'status');
            if (! $change) {
                continue;
            }

            if (! $this->timerService->isEndStatus($change['old_value'] ?? null)
                && $this->timerService->isEndStatus($change['new_value'] ?? null)) {
                return Carbon::parse($event->event_timestamp);
            }
        }

        $fallback = collect([$ticket->resolved_at, $ticket->closed_at])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sortByDesc(fn (Carbon $value) => $value->timestamp)
            ->first();

        return $fallback ?: null;
    }

    private function isEnteringEnd(TicketEvent $event): bool
    {
        if ($event->event_type !== TicketEvent::EVENT_STATUS_CHANGED) {
            return false;
        }

        $change = collect($event->getFieldChanges())->firstWhere('field', 'status');
        if (! $change) {
            return false;
        }

        return ! $this->timerService->isEndStatus($change['old_value'] ?? null)
            && $this->timerService->isEndStatus($change['new_value'] ?? null);
    }
}
