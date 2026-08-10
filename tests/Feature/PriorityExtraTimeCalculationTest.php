<?php

namespace Tests\Feature;

use App\Models\FreshdeskOutboundOperation;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketDueDateChange;
use App\Models\TicketEvent;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Services\Sla\AppTimerSyncService;
use App\Services\Sla\PriorityChangedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PriorityExtraTimeCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_calculation_is_stored_without_mutating_live_due_dates_or_freshdesk_outbox(): void
    {
        [$ticket, $event] = $this->priorityScenario(
            oldPriority: 'High',
            newPriority: 'Urgent',
            oldTtrGoal: 3600,
            newTtrGoal: 2400,
            ttrUsed: 600,
            oldRtGoal: 1800,
            newRtGoal: 1200,
            rtUsed: 300,
            oldTtrDue: '2026-08-10T09:00:00Z',
            oldRtDue: '2026-08-10T08:30:00Z',
            changedAt: '2026-08-10T08:10:00Z'
        );

        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );
        // An event retry must not create a second stage.
        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $stage = TicketSlaStage::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('trigger_type', 'priority_change')
            ->with('metrics')
            ->sole();
        $ttr = $stage->metrics->firstWhere('metric_type', 'ttr');
        $rt = $stage->metrics->firstWhere('metric_type', 'rt');

        $this->assertSame(1200, $ttr->extra_time_granted_seconds);
        $this->assertSame(3000, $ttr->effective_sla_seconds);
        $this->assertSame('eligible', $ttr->eligibility_status);
        $this->assertSame('2026-08-10T09:00:00+00:00', $ttr->adjusted_due_at->toIso8601String());
        $this->assertSame(600, $rt->extra_time_granted_seconds);
        $this->assertSame(1500, $rt->effective_sla_seconds);
        $this->assertSame('2026-08-10T08:35:00+00:00', $rt->adjusted_due_at->toIso8601String());

        $liveTtr = $ticket->getOrCreateTtrMetric()->fresh();
        $liveRt = $ticket->getOrCreateFirstResponseMetric()->fresh();
        $this->assertSame(3600, $liveTtr->total_seconds);
        $this->assertSame('2026-08-10T09:00:00+00:00', $liveTtr->latest_due_date_ttr->toIso8601String());
        $this->assertSame(1800, $liveRt->total_seconds);
        $this->assertSame('2026-08-10T08:30:00+00:00', $liveRt->latest_due_date_rt->toIso8601String());
        $this->assertSame(0, TicketDueDateChange::query()->count());
        $this->assertSame(0, FreshdeskOutboundOperation::query()->count());
    }

    public function test_upgrade_after_failure_preserves_failure_and_grants_no_extra_time(): void
    {
        [$ticket, $event] = $this->priorityScenario(
            oldPriority: 'High',
            newPriority: 'Urgent',
            oldTtrGoal: 3600,
            newTtrGoal: 2400,
            ttrUsed: 1200,
            oldRtGoal: 1800,
            newRtGoal: 1200,
            rtUsed: 300,
            oldTtrDue: '2026-08-10T08:05:00Z',
            oldRtDue: '2026-08-10T08:30:00Z',
            changedAt: '2026-08-10T08:10:00Z'
        );

        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $metric = $this->newMetric($ticket, 'ttr');
        $this->assertSame(0, $metric->extra_time_granted_seconds);
        $this->assertSame('ineligible', $metric->eligibility_status);
        $this->assertSame('fail', $metric->metric_result);
        $this->assertSame('overdue_before_priority_change', $metric->result_reason);
        $this->assertSame('2026-08-10T08:05:00+00:00', $metric->adjusted_due_at->toIso8601String());
    }

    public function test_downgrade_uses_new_policy_without_extra_time(): void
    {
        [$ticket, $event] = $this->priorityScenario(
            oldPriority: 'Urgent',
            newPriority: 'High',
            oldTtrGoal: 2400,
            newTtrGoal: 3600,
            ttrUsed: 600,
            oldRtGoal: 1200,
            newRtGoal: 1800,
            rtUsed: 300,
            oldTtrDue: '2026-08-10T08:40:00Z',
            oldRtDue: '2026-08-10T08:20:00Z',
            changedAt: '2026-08-10T08:10:00Z'
        );

        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $metric = $this->newMetric($ticket, 'ttr');
        $this->assertSame(0, $metric->extra_time_granted_seconds);
        $this->assertSame('not_applicable', $metric->eligibility_status);
        $this->assertSame(3000, $metric->effective_sla_seconds);
        $this->assertSame('2026-08-10T09:00:00+00:00', $metric->adjusted_due_at->toIso8601String());
    }

    public function test_due_driven_and_completed_first_response_are_recorded_without_extra_time(): void
    {
        [$ticket, $event] = $this->priorityScenario(
            oldPriority: 'High',
            newPriority: 'Urgent',
            oldTtrGoal: 3600,
            newTtrGoal: 2400,
            ttrUsed: 600,
            oldRtGoal: 1800,
            newRtGoal: 1200,
            rtUsed: 300,
            oldTtrDue: '2026-08-10T09:00:00Z',
            oldRtDue: '2026-08-10T08:30:00Z',
            changedAt: '2026-08-10T08:10:00Z'
        );
        $ticket->getOrCreateTtrMetric()->update(['processing_mode' => 'due-driven']);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'status' => 'ended_replied',
            'first_response_at' => '2026-08-10T08:05:00Z',
        ]);

        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $stage = TicketSlaStage::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('trigger_type', 'priority_change')
            ->with('metrics')
            ->sole();
        $this->assertSame('due-driven', $stage->processing_mode);
        $this->assertSame(0, $stage->metrics->sum('extra_time_granted_seconds'));
        $this->assertSame('not_applicable', $stage->metrics->firstWhere('metric_type', 'rt')->metric_result);
        $this->assertSame('2026-08-10T09:00:00+00:00', $ticket->getOrCreateTtrMetric()->fresh()->latest_due_date_ttr->toIso8601String());
    }

    public function test_release_one_freshdesk_payload_does_not_expose_stored_extra_time(): void
    {
        [$ticket, $event] = $this->priorityScenario(
            oldPriority: 'High',
            newPriority: 'Urgent',
            oldTtrGoal: 3600,
            newTtrGoal: 2400,
            ttrUsed: 600,
            oldRtGoal: 1800,
            newRtGoal: 1200,
            rtUsed: 300,
            oldTtrDue: '2026-08-10T09:00:00Z',
            oldRtDue: '2026-08-10T08:30:00Z',
            changedAt: '2026-08-10T08:10:00Z'
        );
        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $method = new ReflectionMethod(AppTimerSyncService::class, 'generateCompactJson');
        $payload = $method->invoke(app(AppTimerSyncService::class), $ticket->fresh());

        $this->assertNotSame(0, TicketSlaStageMetric::query()->sum('extra_time_granted_seconds'));
        $this->assertSame([
            'sg' => null,
            'et' => null,
            'pu' => null,
            'pc' => null,
            'dn' => null,
            'rsg' => null,
            'ret' => null,
            'rpu' => null,
            'rfr' => null,
        ], $payload['et']);
    }

    private function priorityScenario(
        string $oldPriority,
        string $newPriority,
        int $oldTtrGoal,
        int $newTtrGoal,
        int $ttrUsed,
        int $oldRtGoal,
        int $newRtGoal,
        int $rtUsed,
        string $oldTtrDue,
        string $oldRtDue,
        string $changedAt
    ): array {
        $oldPolicy = $this->policy($oldPriority, $oldTtrGoal, $oldRtGoal);
        $this->policy($newPriority, $newTtrGoal, $newRtGoal);

        $ticket = Ticket::create([
            'ticket_id' => random_int(20000, 90000),
            'status' => 'Open',
            'priority' => $newPriority,
            'ticket_type' => 'VVIP',
            'fd_created_at' => '2026-08-10T08:00:00Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => $oldTtrGoal,
            'used_seconds' => $ttrUsed,
            'processing_mode' => 'priority-driven',
            'original_due_date_ttr' => $oldTtrDue,
            'latest_due_date_ttr' => $oldTtrDue,
        ]);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => $oldRtGoal,
            'used_seconds' => $rtUsed,
            'status' => 'running',
            'original_due_date_rt' => $oldRtDue,
            'latest_due_date_rt' => $oldRtDue,
        ]);

        $createdEvent = $this->event($ticket, TicketEvent::EVENT_TICKET_CREATED, [], '2026-08-10T08:00:00Z');
        $initialStage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $oldPolicy->id,
            'sequence_number' => 1,
            'priority_stage_number' => 1,
            'trigger_type' => 'initial',
            'priority' => $oldPriority,
            'processing_mode' => 'priority-driven',
            'opened_at' => '2026-08-10T08:00:00Z',
            'opened_by_event_id' => $createdEvent->id,
        ]);
        foreach ([
            ['type' => 'ttr', 'goal' => $oldTtrGoal, 'due' => $oldTtrDue],
            ['type' => 'rt', 'goal' => $oldRtGoal, 'due' => $oldRtDue],
        ] as $metric) {
            TicketSlaStageMetric::create([
                'ticket_sla_stage_id' => $initialStage->id,
                'metric_type' => $metric['type'],
                'sla_goal_seconds' => $metric['goal'],
                'used_before_seconds' => 0,
                'effective_sla_seconds' => $metric['goal'],
                'standard_due_at' => $metric['due'],
                'adjusted_due_at' => $metric['due'],
            ]);
        }

        $changes = [[
            'field' => 'priority',
            'old_value' => $oldPriority,
            'new_value' => $newPriority,
        ]];
        $event = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, $changes, $changedAt);

        return [$ticket, $event];
    }

    private function policy(string $priority, int $ttrGoal, int $rtGoal): SlaPolicy
    {
        return SlaPolicy::create([
            'ticket_type' => 'VVIP',
            'priority' => $priority,
            'version' => 1,
            'total_seconds' => $ttrGoal,
            'l1_seconds' => intdiv($ttrGoal, 4),
            'l2_seconds' => intdiv($ttrGoal, 4),
            'l3_seconds' => intdiv($ttrGoal, 4),
            'l4_seconds' => $ttrGoal - (intdiv($ttrGoal, 4) * 3),
            'rt_seconds' => $rtGoal,
        ]);
    }

    private function event(Ticket $ticket, string $type, array $changes, string $at): TicketEvent
    {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', $ticket->ticket_id . $type . $at),
            'event_type' => $type,
            'event_data' => [
                'ticket_data' => [
                    'priority' => $ticket->priority,
                    'updated_at' => $at,
                ],
            ],
            'field_changes' => $changes,
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => $at,
            'received_at' => $at,
        ]);
    }

    private function newMetric(Ticket $ticket, string $type): TicketSlaStageMetric
    {
        $stage = TicketSlaStage::query()
            ->where('ticket_id', $ticket->ticket_id)
            ->where('trigger_type', 'priority_change')
            ->sole();

        return $stage->metrics()->where('metric_type', $type)->sole();
    }
}
