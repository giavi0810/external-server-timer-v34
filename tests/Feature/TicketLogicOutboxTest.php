<?php

namespace Tests\Feature;

use App\Models\FreshdeskOutboundOperation;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketLogicOutbox;
use App\Jobs\ExecuteFreshdeskOutboundOperationJob;
use App\Jobs\ProcessTicketEventJob;
use App\Services\FreshdeskApiService;
use App\Services\Queue\TicketLogicOutboxService;
use App\Services\Queue\FreshdeskOutboundService;
use App\Services\Sla\AppTimerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TicketLogicOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_event_raises_generation_epoch_and_supersedes_old_sla_sync(): void
    {
        Ticket::create([
            'ticket_id' => 18321,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $first = TicketEvent::create([
            'ticket_id' => 18321,
            'idempotency_key' => hash('sha256', 'first'),
            'event_type' => TicketEvent::EVENT_TICKET_CREATED,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PENDING,
            'event_timestamp' => '2026-07-30T08:42:30Z',
            'received_at' => now(),
        ]);

        $service = app(TicketLogicOutboxService::class);
        $outbox = $service->requestForEvent($first);
        $this->assertSame(1, $outbox->fresh()->requested_generation);
        $this->assertSame(1, $first->fresh()->logic_generation);

        FreshdeskOutboundOperation::create([
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => 'old-sla-sync',
            'ticket_id' => 18321,
            'operation_type' => 'sync_sla',
            'coalesce_key' => 'sla-sync',
            'generation' => 1,
            'sync_epoch' => 0,
            'state' => 'ready',
            'available_at' => now(),
        ]);
        $late = TicketEvent::create([
            'ticket_id' => 18321,
            'idempotency_key' => hash('sha256', 'late'),
            'event_type' => TicketEvent::EVENT_STATUS_CHANGED,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PENDING,
            'event_timestamp' => '2026-07-30T08:41:30Z',
            'received_at' => now(),
        ]);

        $outbox = $service->requestForEvent($late, true)->fresh();
        $this->assertSame('replay_requested', $outbox->state);
        $this->assertSame(2, $outbox->requested_generation);
        $this->assertSame(1, $outbox->sync_epoch);
        $this->assertSame('superseded', FreshdeskOutboundOperation::first()->state);
    }

    public function test_dispatch_state_and_token_are_committed_before_normal_job_is_pushed(): void
    {
        Queue::fake();
        TicketLogicOutbox::create([
            'ticket_id' => 18321,
            'state' => 'ready',
            'dispatch_kind' => 'normal',
            'requested_generation' => 4,
            'acked_generation' => 3,
            'sync_epoch' => 2,
            'available_at' => now()->subSecond(),
        ]);

        $this->artisan('ticket-logic-outbox:dispatch --limit=1')->assertSuccessful();

        $outbox = TicketLogicOutbox::first();
        $this->assertSame('dispatched', $outbox->state);
        $this->assertNotNull($outbox->lease_token);
        $this->assertNotNull($outbox->visibility_at);
        Queue::assertPushed(ProcessTicketEventJob::class);
    }

    public function test_replay_epoch_does_not_duplicate_event_business_operation(): void
    {
        Ticket::create([
            'ticket_id' => 18322,
            'status' => 'Closed',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $event = TicketEvent::create([
            'ticket_id' => 18322,
            'idempotency_key' => hash('sha256', 'requester-event'),
            'event_type' => TicketEvent::EVENT_REQUESTER_REPLIED,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PENDING,
            'event_timestamp' => '2026-07-30T08:42:30Z',
            'received_at' => now(),
        ]);
        $logic = app(TicketLogicOutboxService::class);
        $logic->requestForEvent($event);
        $outbound = app(FreshdeskOutboundService::class);
        $first = $outbound->enqueueForEvent($event->fresh(), 'reopen_ticket', 'requester-reopen', []);

        $late = TicketEvent::create([
            'ticket_id' => 18322,
            'idempotency_key' => hash('sha256', 'late-requester-event'),
            'event_type' => TicketEvent::EVENT_STATUS_CHANGED,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PENDING,
            'event_timestamp' => '2026-07-30T08:41:30Z',
            'received_at' => now(),
        ]);
        $logic->requestForEvent($late, true);
        $same = $outbound->enqueueForEvent($event->fresh(), 'reopen_ticket', 'requester-reopen', []);

        $this->assertSame($first->operation_id, $same->operation_id);
        $this->assertSame(1, FreshdeskOutboundOperation::where('ticket_id', 18322)->count());
    }

    public function test_completed_outbound_operation_releases_its_lease(): void
    {
        Ticket::create([
            'ticket_id' => 18323,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $operationId = (string) Str::uuid();
        $leaseToken = (string) Str::uuid();
        FreshdeskOutboundOperation::create([
            'operation_id' => $operationId,
            'idempotency_key' => 'sla-sync:18323:1:0',
            'ticket_id' => 18323,
            'operation_type' => 'sync_sla',
            'coalesce_key' => 'sla-sync',
            'generation' => 1,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'state' => 'dispatched',
            'lease_token' => $leaseToken,
            'available_at' => now(),
        ]);

        $syncService = $this->mock(AppTimerSyncService::class);
        $syncService->shouldReceive('syncTicket')->once();

        (new ExecuteFreshdeskOutboundOperationJob(
            $operationId,
            $leaseToken,
            1,
            0,
            1
        ))->handle($syncService, $this->mock(FreshdeskApiService::class));

        $operation = FreshdeskOutboundOperation::query()->findOrFail($operationId);
        $this->assertSame('completed', $operation->state);
        $this->assertNull($operation->lease_token);
        $this->assertNull($operation->visibility_at);
        $this->assertNotNull($operation->completed_at);
    }
}
