<?php

namespace Tests\Feature;

use App\Jobs\ExecuteFreshdeskOutboundOperationJob;
use App\Models\FreshdeskOutboundOperation;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketHistory;
use App\Services\FreshdeskApiService;
use App\Services\Sla\AppTimerSyncService;
use App\Services\Sla\DueDateChangedHandler;
use App\Services\Sla\SlaInitializationService;
use App\Services\Sla\SlaStageService;
use App\Services\Sla\TimelineService;
use App\Services\Sla\TimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReleaseOneSafetyFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freshdesk.basic_auth.username' => 'qa-user',
            'freshdesk.basic_auth.password' => 'qa-password',
        ]);
    }

    public function test_change_due_date_is_accepted_once_without_calling_freshdesk_in_http_request(): void
    {
        Http::fake();
        Ticket::create([
            'ticket_id' => 18321,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'VVIP SLA',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $payload = [
            'ticket_id' => 18321,
            'new_due_date' => '2026-08-05T08:00:00Z',
            'processing_phase' => 'L4',
            'reason' => 'QA adjustment',
            'agent_name' => 'QA',
        ];

        $first = $this->withBasicAuth('qa-user', 'qa-password')
            ->withHeader('Idempotency-Key', 'qa-change-18321-1')
            ->postJson('/api/tickets/change-due-date', $payload);
        $second = $this->withBasicAuth('qa-user', 'qa-password')
            ->withHeader('Idempotency-Key', 'qa-change-18321-1')
            ->postJson('/api/tickets/change-due-date', $payload);

        $first->assertStatus(202)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false);
        $second->assertStatus(202)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('operation_id', $first->json('operation_id'));
        $this->assertSame(1, FreshdeskOutboundOperation::query()->count());
        $this->assertSame(
            'change_due_date',
            FreshdeskOutboundOperation::query()->value('operation_type')
        );
        Http::assertNothingSent();
    }

    public function test_history_deduplication_keeps_identical_replies_from_different_events(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18322,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $first = $this->event($ticket, 'first', '2026-07-30T08:01:00Z');
        $second = $this->event($ticket, 'second', '2026-07-30T08:02:00Z');
        $timeline = app(TimelineService::class);

        $timeline->appendTicketEventLog($ticket, 'a', 'rep', '2026-07-30T08:01:00Z', '100', $first);
        $timeline->appendTicketEventLog($ticket, 'a', 'rep', '2026-07-30T08:02:00Z', '100', $second);
        $timeline->appendTicketEventLog($ticket, 'a', 'rep', '2026-07-30T08:02:00Z', '100', $second);

        $this->assertSame(2, TicketHistory::query()->count());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            TicketHistory::query()->pluck('ticket_event_id')->all()
        );
    }

    public function test_change_due_date_outbound_operation_updates_freshdesk_once(): void
    {
        config([
            'freshdesk.domain' => 'freshdesk.test',
            'freshdesk.api_key' => 'test-key',
        ]);
        Ticket::create([
            'ticket_id' => 18324,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $operationId = (string) Str::uuid();
        $leaseToken = (string) Str::uuid();
        FreshdeskOutboundOperation::create([
            'operation_id' => $operationId,
            'idempotency_key' => 'change-due-date:test',
            'ticket_id' => 18324,
            'operation_type' => 'change_due_date',
            'coalesce_key' => 'change_due_date',
            'generation' => 0,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'payload' => [
                'new_due_date' => '2026-08-05T08:00:00+00:00',
                'processing_phase' => 'L4',
                'reason' => 'QA adjustment',
                'agent_name' => 'QA',
            ],
            'state' => 'dispatched',
            'lease_token' => $leaseToken,
            'available_at' => now(),
        ]);
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => 18324,
                    'tags' => [],
                    'custom_fields' => [
                        'cf_number_of_due_date_changes_2885394' => 0,
                        'cf_processing_mode_2885394' => 'priority-driven',
                        'cf_processing_phase_2885394' => null,
                        'cf_change_due_reason_2885394' => null,
                    ],
                ]);
            }

            return Http::response(['success' => true], 200);
        });

        (new ExecuteFreshdeskOutboundOperationJob(
            $operationId,
            $leaseToken,
            0,
            0,
            1
        ))->handle(
            $this->mock(AppTimerSyncService::class),
            app(FreshdeskApiService::class)
        );

        $operation = FreshdeskOutboundOperation::query()->findOrFail($operationId);
        $this->assertSame('completed', $operation->state);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request['due_by'] === '2026-08-05T08:00:00+00:00'
            && $request['custom_fields']['cf_processing_mode_2885394'] === 'due-driven'
            && in_array('due_date_change (1)', $request['tags'], true)
        );
        Http::assertSentCount(3);
    }

    public function test_due_date_webhook_preserves_incoming_due_driven_mode(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18323,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'NO_POLICY',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'latest_due_date_ttr' => '2026-08-05T08:00:00Z',
        ]);
        $event = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', 'due-driven-event'),
            'event_type' => TicketEvent::EVENT_DUE_DATE_CHANGED,
            'event_data' => [
                'ticket_data' => [
                    'due_by' => '2026-08-04T08:00:00Z',
                    'custom_fields' => [
                        'cf_processing_mode_2885394' => 'due-driven',
                    ],
                ],
            ],
            'field_changes' => [[
                'field' => 'due_by',
                'old_value' => '2026-08-05T08:00:00Z',
                'new_value' => '2026-08-04T08:00:00Z',
            ]],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-08-01T08:00:00Z',
            'received_at' => now(),
        ]);

        $initialization = Mockery::mock(SlaInitializationService::class);
        $initialization->shouldReceive('ensureSlaInitialized')->once();
        $timeline = Mockery::mock(TimelineService::class);
        $timeline->shouldReceive('appendTicketEventLog')->once();
        $handler = new DueDateChangedHandler(
            Mockery::mock(TimerService::class),
            $initialization,
            $timeline,
            Mockery::mock(SlaStageService::class)
        );

        $handler->handle($ticket->ticket_id, $event->getTicketData(), $event->getFieldChanges(), $event);

        $this->assertSame('due-driven', $ticket->getOrCreateTtrMetric()->fresh()->processing_mode);
    }

    private function event(Ticket $ticket, string $key, string $occurredAt): TicketEvent
    {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', $key),
            'event_type' => TicketEvent::EVENT_AGENT_REPLIED,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSED,
            'event_timestamp' => $occurredAt,
            'received_at' => $occurredAt,
            'processed_at' => $occurredAt,
        ]);
    }
}
