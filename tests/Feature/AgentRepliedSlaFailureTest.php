<?php

namespace Tests\Feature;

use App\Models\FreshdeskGroup;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Services\FreshdeskApiService;
use App\Services\Sla\AgentRepliedHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentRepliedSlaFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_replied_after_rt_overdue_marks_stage_fail_and_adds_tag(): void
    {
        FreshdeskGroup::create([
            'group_id' => '1001',
            'name' => 'L1 Support',
            'main_layer' => 'L1',
            'is_active' => true,
        ]);

        $policy = SlaPolicy::create([
            'ticket_type' => 'VVIP SLA',
            'priority' => 'Urgent',
            'version' => 1,
            'rt_seconds' => 3600,
            'total_seconds' => 14400,
            'l1_seconds' => 1800,
            'l2_seconds' => 3600,
            'l3_seconds' => 5400,
            'l4_seconds' => 3600,
        ]);

        $ticket = Ticket::create([
            'ticket_id' => 18870,
            'subject' => 'Test ticket',
            'status' => 'Processing',
            'priority' => 'Urgent',
            'ticket_type' => 'VVIP SLA',
            'group_id' => '1001',
            'fd_created_at' => Carbon::parse('2026-09-21T02:54:19Z'),
        ]);

        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        $rtMetric->total_seconds = 3600;
        $rtMetric->latest_due_date_rt = Carbon::parse('2026-09-21T06:54:19Z');
        $rtMetric->started_at = Carbon::parse('2026-09-21T02:54:19Z');
        $rtMetric->status = 'running';
        $rtMetric->save();

        $openEvent = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => 'stage_open_event_73',
            'event_type' => 'due_date_changed',
            'event_data' => [],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSED,
            'event_timestamp' => '2026-09-21T03:08:11Z',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $stage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $policy->id,
            'sequence_number' => 5,
            'priority_stage_number' => null,
            'trigger_type' => 'due_date_change',
            'priority' => 'Urgent',
            'processing_mode' => 'due-driven',
            'opened_at' => Carbon::parse('2026-09-21T03:08:11Z'),
            'opened_by_event_id' => $openEvent->id,
        ]);

        $stageRtMetric = TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => 'rt',
            'sla_goal_seconds' => 3600,
            'used_before_seconds' => 832,
            'effective_sla_seconds' => 3600,
            'old_due_at' => '2026-09-21T10:54:19Z',
            'standard_due_at' => '2026-09-21T06:54:19Z',
            'adjusted_due_at' => '2026-09-21T06:54:19Z',
            'metric_result' => 'pending',
        ]);

        $event = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => 'agent_reply_test_1',
            'event_type' => 'agent_replied',
            'event_data' => [
                'conversation_data' => [
                    'actor_id' => 9999,
                    'actor_type' => 'agent',
                ],
            ],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-09-23T04:32:38Z',
            'received_at' => now(),
        ]);

        $freshdesk = $this->mock(FreshdeskApiService::class);
        $freshdesk->shouldReceive('addTagToTicket')
            ->once()
            ->with($ticket->ticket_id, 'has_stage_fail_SLA')
            ->andReturnTrue();

        app(AgentRepliedHandler::class)->handle(
            $ticket->ticket_id,
            ['id' => $ticket->ticket_id],
            $event
        );

        $stageRtMetric->refresh();
        $this->assertSame('fail', $stageRtMetric->metric_result);
        $this->assertSame('agent_replied_after_due', $stageRtMetric->result_reason);
        $this->assertSame('1001', (string) $stageRtMetric->overdue_owner_group_id);
    }
}
