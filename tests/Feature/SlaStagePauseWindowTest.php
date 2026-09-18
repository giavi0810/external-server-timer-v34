<?php

namespace Tests\Feature;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Services\Sla\SlaStageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaStagePauseWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_before_stage_open_is_not_added_to_stage_due_date(): void
    {
        [$ticket, $stage] = $this->stageScenario('priority-driven', 'rt');
        $this->statusEvent($ticket, 'Processing', 'Waiting For Customer', '2026-09-18 08:30:00');
        $this->statusEvent($ticket, 'Waiting For Customer', 'Processing', '2026-09-18 09:30:00');
        $checkpoint = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, '2026-09-18 11:30:00');

        app(SlaStageService::class)->checkpointOpenStage(
            $ticket,
            $checkpoint,
            Carbon::parse('2026-09-18 11:30:00'),
            'priority_changed'
        );

        $metric = $stage->fresh()->metrics()->sole();
        $this->assertSame('fail', $metric->metric_result);
        $this->assertSame('2026-09-18 11:00:00', $metric->overdue_at->format('Y-m-d H:i:s'));
    }

    public function test_pause_inside_stage_extends_priority_driven_stage_due_date(): void
    {
        [$ticket, $stage] = $this->stageScenario('priority-driven', 'rt');
        $this->statusEvent($ticket, 'Processing', 'Pending', '2026-09-18 10:30:00');
        $ticket->update(['status' => 'Pending']);
        $checkpoint = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, '2026-09-18 11:30:00');

        app(SlaStageService::class)->checkpointOpenStage(
            $ticket,
            $checkpoint,
            Carbon::parse('2026-09-18 11:30:00'),
            'priority_changed'
        );

        $metric = $stage->fresh()->metrics()->sole();
        $this->assertSame('pass', $metric->metric_result);
        $this->assertNull($metric->overdue_at);
    }

    public function test_due_driven_ttr_does_not_exclude_pause_inside_stage(): void
    {
        [$ticket, $stage] = $this->stageScenario('due-driven', 'ttr');
        $this->statusEvent($ticket, 'Processing', 'Pending', '2026-09-18 10:30:00');
        $ticket->update(['status' => 'Pending']);
        $checkpoint = $this->event($ticket, TicketEvent::EVENT_DUE_DATE_CHANGED, '2026-09-18 11:30:00');

        app(SlaStageService::class)->checkpointOpenStage(
            $ticket,
            $checkpoint,
            Carbon::parse('2026-09-18 11:30:00'),
            'due_date_changed'
        );

        $metric = $stage->fresh()->metrics()->sole();
        $this->assertSame('fail', $metric->metric_result);
        $this->assertSame('2026-09-18 11:00:00', $metric->overdue_at->format('Y-m-d H:i:s'));
    }

    public function test_pause_already_open_when_stage_starts_counts_only_stage_overlap(): void
    {
        [$ticket, $stage] = $this->stageScenario('priority-driven', 'rt');
        $ticket->update(['status' => 'Pending']);
        $openedEvent = $stage->openedByEvent()->firstOrFail();
        $openedEvent->update(['event_data' => ['ticket_data' => [
            'status' => 'Pending',
            'updated_at' => '2026-09-18 10:00:00',
        ]]]);
        $checkpoint = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, '2026-09-18 11:30:00');

        app(SlaStageService::class)->checkpointOpenStage(
            $ticket,
            $checkpoint,
            Carbon::parse('2026-09-18 11:30:00'),
            'priority_changed'
        );

        $metric = $stage->fresh()->metrics()->sole();
        $this->assertSame('pass', $metric->metric_result);
        $this->assertNull($metric->overdue_at);
    }

    public function test_pause_after_first_reply_does_not_extend_rt_evaluation(): void
    {
        [$ticket, $stage] = $this->stageScenario('priority-driven', 'rt');
        $ticket->getOrCreateFirstResponseMetric()->update([
            'status' => 'ended_replied',
            'first_response_at' => '2026-09-18 11:30:00',
        ]);
        $this->statusEvent($ticket, 'Processing', 'Pending', '2026-09-18 12:00:00');
        $ticket->update(['status' => 'Pending']);
        $checkpoint = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, '2026-09-18 13:00:00');

        app(SlaStageService::class)->checkpointOpenStage(
            $ticket,
            $checkpoint,
            Carbon::parse('2026-09-18 13:00:00'),
            'priority_changed'
        );

        $metric = $stage->fresh()->metrics()->sole();
        $this->assertSame('fail', $metric->metric_result);
        $this->assertSame('2026-09-18 11:00:00', $metric->overdue_at->format('Y-m-d H:i:s'));
    }

    /** @return array{Ticket, TicketSlaStage} */
    private function stageScenario(string $mode, string $metricType): array
    {
        $policy = SlaPolicy::create([
            'ticket_type' => 'VIP SLA',
            'priority' => 'High',
            'version' => 1,
            'total_seconds' => 40000,
            'l1_seconds' => 10000,
            'l2_seconds' => 10000,
            'l3_seconds' => 10000,
            'l4_seconds' => 10000,
            'rt_seconds' => 10000,
        ]);
        $ticket = Ticket::create([
            'ticket_id' => 93001,
            'status' => 'Processing',
            'priority' => 'High',
            'ticket_type' => 'VIP SLA',
            'fd_created_at' => '2026-09-18 08:00:00',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => $mode,
            'total_seconds' => 40000,
            'used_seconds' => 1000,
        ]);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'status' => 'running',
            'total_seconds' => 10000,
            'used_seconds' => 1000,
        ]);
        $opened = $this->event(
            $ticket,
            TicketEvent::EVENT_PRIORITY_CHANGED,
            '2026-09-18 10:00:00'
        );
        $stage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $policy->id,
            'sequence_number' => 1,
            'priority_stage_number' => 1,
            'trigger_type' => 'priority_change',
            'priority' => 'High',
            'processing_mode' => $mode,
            'opened_at' => '2026-09-18 10:00:00',
            'opened_by_event_id' => $opened->id,
        ]);
        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => $metricType,
            'sla_goal_seconds' => 10000,
            'used_before_seconds' => 1000,
            'effective_sla_seconds' => 10000,
            'adjusted_due_at' => '2026-09-18 11:00:00',
        ]);

        return [$ticket, $stage];
    }

    private function statusEvent(Ticket $ticket, string $old, string $new, string $at): TicketEvent
    {
        $event = $this->event($ticket, TicketEvent::EVENT_STATUS_CHANGED, $at);
        $event->update(['field_changes' => [[
            'field' => 'status',
            'old_value' => $old,
            'new_value' => $new,
        ]]]);

        return $event->fresh();
    }

    private function event(Ticket $ticket, string $type, string $at): TicketEvent
    {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', implode(':', [
                $ticket->ticket_id,
                $type,
                $at,
                TicketEvent::query()->count(),
            ])),
            'event_type' => $type,
            'event_data' => ['ticket_data' => [
                'status' => $ticket->status,
                'updated_at' => $at,
            ]],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => $at,
            'received_at' => $at,
        ]);
    }
}
