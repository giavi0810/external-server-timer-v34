<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Sla\AppTimerSyncService;
use App\Services\Sla\OverdueSyncScanner;
use App\Services\Sla\SlaComplianceService;
use App\Services\Sla\TicketReplayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SlaComplianceRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rt_final_uses_end_timestamp_instead_of_sync_time(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $ticket = $this->ticket(92001, 'Closed');
        $ticket->update(['closed_at' => '2026-09-01 09:00:00']);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => 7200,
            'used_seconds' => 3600,
            'status' => 'ended_closed_no_reply',
            'started_at' => null,
            'latest_due_date_rt' => '2026-09-01 10:00:00',
        ]);

        $this->assertFalse(app(SlaComplianceService::class)->currentRtOverdue($ticket->fresh()));
    }

    public function test_rt_due_date_does_not_advance_while_paused(): void
    {
        $ticket = $this->ticket(92011, 'Pending');
        $metric = $ticket->getOrCreateFirstResponseMetric();
        $metric->update([
            'total_seconds' => 3600,
            'used_seconds' => 300,
            'status' => 'paused',
            'started_at' => null,
            'latest_due_date_rt' => '2026-09-01 09:00:00',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => 86400,
            'used_seconds' => 0,
            'latest_due_date_ttr' => '2026-09-02 08:00:00',
        ]);
        $service = app(SlaComplianceService::class);

        $snapshot = $service->captureCurrent(
            $ticket,
            Carbon::parse('2026-09-01 10:00:00')
        );

        $this->assertFalse($snapshot['rt']);
        $this->assertFalse($ticket->fresh()->sla_violated);

        $metric->update(['used_seconds' => 3601]);

        $this->assertTrue($service->currentRtOverdue(
            $ticket->fresh(),
            Carbon::parse('2026-09-01 10:00:00')
        ));
    }

    public function test_sla_violation_stays_failed_after_live_rt_returns_to_no(): void
    {
        $ticket = $this->ticket(92002);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => 7200,
            'used_seconds' => 0,
            'status' => 'running',
            'started_at' => '2026-09-01 08:00:00',
            'latest_due_date_rt' => '2026-09-01 09:00:00',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => 86400,
            'latest_due_date_ttr' => '2026-09-02 08:00:00',
        ]);
        $event = $this->event($ticket, 'priority_changed', '2026-09-01 10:00:00');
        $service = app(SlaComplianceService::class);

        $service->captureEvent($event);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => 14400,
            'latest_due_date_rt' => '2026-09-01 12:00:00',
        ]);

        $this->assertFalse($service->currentRtOverdue($ticket->fresh(), Carbon::parse('2026-09-01 10:30:00')));
        $this->assertTrue($ticket->fresh()->sla_violated);
        $this->assertSame('rt', $ticket->fresh()->sla_violation_metric);
    }

    public function test_final_compliance_is_replaced_only_by_next_end_cycle(): void
    {
        $ticket = $this->ticket(92003);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => 3600,
            'used_seconds' => 3601,
            'processing_mode' => 'priority-driven',
        ]);
        $ticket->update(['status' => 'Closed', 'closed_at' => '2026-09-01 09:00:00']);
        $service = app(SlaComplianceService::class);
        $service->captureEvent($this->statusEvent($ticket, 'Open', 'Closed', '2026-09-01 09:00:00'));
        $this->assertFalse($ticket->fresh()->final_sla_compliant);

        $ticket->update(['status' => 'Resolved']);
        $service->captureEvent($this->statusEvent($ticket, 'Closed', 'Resolved', '2026-09-01 09:30:00'));
        $this->assertFalse($ticket->fresh()->final_sla_compliant);

        $ticket->update(['status' => 'Processing']);
        $ticket->getOrCreateTtrMetric()->update(['used_seconds' => 3000]);
        $this->assertFalse($ticket->fresh()->final_sla_compliant);

        $ticket->update(['status' => 'Closed']);
        $service->captureEvent($this->statusEvent($ticket, 'Processing', 'Closed', '2026-09-01 10:00:00'));
        $this->assertTrue($ticket->fresh()->final_sla_compliant);
    }

    public function test_end_compliance_uses_the_rule_of_each_processing_mode(): void
    {
        $service = app(SlaComplianceService::class);

        $priorityTicket = $this->ticket(92007, 'Closed');
        $priorityTicket->getOrCreateTtrMetric()->update([
            'total_seconds' => 3600,
            'used_seconds' => 3000,
            'processing_mode' => 'priority-driven',
            // A priority-driven final result is based on Used, not Due Date.
            'latest_due_date_ttr' => '2026-09-01 08:30:00',
        ]);
        $service->captureEvent($this->statusEvent(
            $priorityTicket,
            'Processing',
            'Closed',
            '2026-09-01 09:00:00'
        ));

        $dueTicket = $this->ticket(92008, 'Closed');
        $dueTicket->getOrCreateTtrMetric()->update([
            'total_seconds' => 3600,
            'used_seconds' => 7200,
            'processing_mode' => 'due-driven',
            // A due-driven final result is based on the End timestamp and Due Date.
            'latest_due_date_ttr' => '2026-09-01 10:00:00',
        ]);
        $service->captureEvent($this->statusEvent(
            $dueTicket,
            'Pending',
            'Closed',
            '2026-09-01 09:00:00'
        ));

        $this->assertTrue($priorityTicket->fresh()->final_sla_compliant);
        $this->assertTrue($dueTicket->fresh()->final_sla_compliant);
    }

    public function test_legacy_ended_ticket_without_final_snapshot_uses_end_event_time(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        $ticket = $this->ticket(92009, 'Closed');
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'due-driven',
            'latest_due_date_ttr' => '2026-09-01 10:00:00',
        ]);
        $this->statusEvent($ticket, 'Processing', 'Closed', '2026-09-01 09:00:00');

        $this->assertFalse(app(SlaComplianceService::class)->currentTtrOverdue($ticket->fresh()));
    }

    public function test_due_driven_ttr_scanner_includes_pause_and_marks_history(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $priorityPaused = $this->ticket(92004, 'Pending');
        $priorityPaused->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'latest_due_date_ttr' => '2026-09-01 09:00:00',
        ]);

        $duePaused = $this->ticket(92005, 'Pending');
        $duePaused->getOrCreateTtrMetric()->update([
            'processing_mode' => 'due-driven',
            'latest_due_date_ttr' => '2026-09-01 09:00:00',
        ]);

        $result = app(OverdueSyncScanner::class)->scan(500, Carbon::now());

        $this->assertSame(1, $result['ttr']);
        $this->assertFalse($priorityPaused->fresh()->sla_violated);
        $this->assertTrue($duePaused->fresh()->sla_violated);
        $this->assertSame('ttr', $duePaused->fresh()->sla_violation_metric);
    }

    public function test_freshdesk_payload_contains_all_four_sla_fields(): void
    {
        $ticket = $this->ticket(92006, 'Closed');
        $ticket->update([
            'sla_violated' => true,
            'sla_violated_at' => '2026-09-01 09:00:00',
            'sla_violation_metric' => 'rt',
            'final_sla_compliant' => false,
        ]);
        $method = new ReflectionMethod(AppTimerSyncService::class, 'buildSlaCustomFields');
        $fields = $method->invoke(app(AppTimerSyncService::class), $ticket->fresh());

        $this->assertArrayHasKey('cf_rt_overdue', $fields);
        $this->assertArrayHasKey('cf_ttr_overdue', $fields);
        $this->assertSame('Fail', $fields['cf_sla_violated']);
        $this->assertSame('Fail', $fields['cf_final_sla_compliance']);
    }

    public function test_replay_preserves_violation_history_and_rebuilds_final_result(): void
    {
        $ticket = $this->ticket(92010, 'Closed');
        $ticket->update([
            'sla_violated' => true,
            'sla_violated_at' => '2026-09-01 09:00:00',
            'sla_violation_metric' => 'rt',
            'final_sla_compliant' => false,
        ]);
        $this->event($ticket, TicketEvent::EVENT_TICKET_CREATED, '2026-09-01 08:00:00');

        app(TicketReplayService::class)->prepare($ticket->ticket_id);

        $ticket->refresh();
        $this->assertTrue($ticket->sla_violated);
        $this->assertSame('2026-09-01 09:00:00', $ticket->sla_violated_at->format('Y-m-d H:i:s'));
        $this->assertSame('rt', $ticket->sla_violation_metric);
        $this->assertNull($ticket->final_sla_compliant);
    }

    private function ticket(int $id, string $status = 'Open'): Ticket
    {
        return Ticket::create([
            'ticket_id' => $id,
            'status' => $status,
            'priority' => 'High',
            'fd_created_at' => '2026-09-01 08:00:00',
        ]);
    }

    private function event(Ticket $ticket, string $type, string $at): TicketEvent
    {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', "{$ticket->ticket_id}:{$type}:{$at}"),
            'event_type' => $type,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => $at,
            'received_at' => $at,
        ]);
    }

    private function statusEvent(Ticket $ticket, string $oldStatus, string $newStatus, string $at): TicketEvent
    {
        $event = $this->event($ticket, TicketEvent::EVENT_STATUS_CHANGED, $at);
        $event->update(['field_changes' => [[
            'field' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
        ]]]);

        return $event->fresh();
    }
}
