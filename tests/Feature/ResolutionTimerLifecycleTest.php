<?php

namespace Tests\Feature;

use App\Models\FreshdeskGroup;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketGroupMetric;
use App\Models\TicketStatusMetric;
use App\Services\Sla\AppTimerSyncService;
use App\Services\Sla\StatusChangedHandler;
use App\Services\Sla\TimerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolutionTimerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FreshdeskGroup::create([
            'group_id' => '101',
            'name' => 'L1 Support',
            'main_layer' => 'L1',
            'is_active' => true,
            'is_default_assignment' => true,
        ]);

        SlaPolicy::create([
            'ticket_type' => 'Default',
            'priority' => 'Medium',
            'version' => 1,
            'total_seconds' => 72000,
            'l1_seconds' => 7200,
            'l2_seconds' => 14400,
            'l3_seconds' => 28800,
            'l4_seconds' => 21600,
            'rt_seconds' => 1800,
        ]);
    }

    public function test_resolution_timer_accumulates_across_multiple_close_and_reopen_cycles(): void
    {
        $createdAt = Carbon::parse('2026-09-03 08:00:00');
        $ticket = Ticket::create([
            'ticket_id' => 18697,
            'status' => 'Open',
            'priority' => 'Medium',
            'ticket_type' => 'Default',
            'group_id' => 101,
            'fd_created_at' => $createdAt->toIso8601String(),
        ]);

        $handler = app(StatusChangedHandler::class);
        $syncService = app(AppTimerSyncService::class);

        // Chu kỳ 1: Open -> Waiting For Customer lúc 08:30:00 (chạy 1800s)
        $event1 = $this->createStatusEvent($ticket, 'Open', 'Waiting For Customer', Carbon::parse('2026-09-03 08:30:00'));
        $handler->handle($ticket->ticket_id, ['status' => 6], [['field' => 'status', 'old_value' => 'Open', 'new_value' => 'Waiting For Customer']], $event1);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertNotNull($statusMetric->resolution_started_at, 'Resolution timer phải tiếp tục chạy ở Waiting For Customer');

        // Chu kỳ 1: Waiting For Customer -> Closed lúc 08:41:36 (chạy thêm 696s => tổng 2496s)
        $event2 = $this->createStatusEvent($ticket, 'Waiting For Customer', 'Closed', Carbon::parse('2026-09-03 08:41:36'));
        $handler->handle($ticket->ticket_id, ['status' => 5], [['field' => 'status', 'old_value' => 'Waiting For Customer', 'new_value' => 'Closed']], $event2);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertSame(2496, $statusMetric->resolution_total_seconds, 'Lần Closed 1 phải chốt đúng 2496s (41m 36s)');
        $this->assertNull($statusMetric->resolution_started_at, 'Resolution started_at phải null khi vé đã Closed');

        // Đồng bộ JSON kiểm tra rs ở lần Closed 1
        $json1 = $syncService->generateCompactJson($ticket->fresh());
        $this->assertSame(2496000, $json1['rs']['us']);
        $this->assertNull($json1['rs']['rs']);

        // Chu kỳ 2 (Reopen): Closed -> Processing lúc 2026-09-04 02:43:00 (Event 46)
        $event3 = $this->createStatusEvent($ticket, 'Closed', 'Processing', Carbon::parse('2026-09-04 02:43:00'));
        $handler->handle($ticket->ticket_id, ['status' => 3], [['field' => 'status', 'old_value' => 'Closed', 'new_value' => 'Processing']], $event3);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertNotNull($statusMetric->resolution_started_at, 'Khi reopen sang Processing, resolution_started_at BẮT BUỘC phải được bật lại');
        $this->assertSame('2026-09-04 02:43:00', Carbon::parse($statusMetric->resolution_started_at)->format('Y-m-d H:i:s'));

        // Chuyển Processing -> Waiting For Customer rồi Waiting For Customer -> Processing
        $event4 = $this->createStatusEvent($ticket, 'Processing', 'Waiting For Customer', Carbon::parse('2026-09-04 10:00:00'));
        $handler->handle($ticket->ticket_id, ['status' => 6], [['field' => 'status', 'old_value' => 'Processing', 'new_value' => 'Waiting For Customer']], $event4);

        $event5 = $this->createStatusEvent($ticket, 'Waiting For Customer', 'Processing', Carbon::parse('2026-09-05 08:00:00'));
        $handler->handle($ticket->ticket_id, ['status' => 3], [['field' => 'status', 'old_value' => 'Waiting For Customer', 'new_value' => 'Processing']], $event5);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertNotNull($statusMetric->resolution_started_at, 'Sau khi Pause -> Run, resolution_started_at vẫn phải tồn tại');

        // Chu kỳ 2: Processing -> Closed lần 2 lúc 2026-09-07 07:24:00 (Event 176)
        $event6 = $this->createStatusEvent($ticket, 'Processing', 'Closed', Carbon::parse('2026-09-07 07:24:00'));
        $handler->handle($ticket->ticket_id, ['status' => 5], [['field' => 'status', 'old_value' => 'Processing', 'new_value' => 'Closed']], $event6);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertNull($statusMetric->resolution_started_at);
        // Thời gian chu kỳ 2 là từ 2026-09-04 02:43:00 đến 2026-09-07 07:24:00 = 276.060 giây
        // Tổng thời gian = 2496 + 276060 = 278556 giây
        $this->assertGreaterThan(2496, $statusMetric->resolution_total_seconds, 'Lần Closed 2 PHẢI cộng dồn thời gian chu kỳ 2, không thể kẹt ở 2496s!');
        $this->assertSame(2496 + (Carbon::parse('2026-09-07 07:24:00')->timestamp - Carbon::parse('2026-09-04 02:43:00')->timestamp), $statusMetric->resolution_total_seconds);
    }

    public function test_finalize_resolution_time_recovers_missing_started_at_from_fallback(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 99999,
            'status' => 'Processing',
            'priority' => 'Medium',
            'ticket_type' => 'Default',
            'group_id' => 101,
            'fd_created_at' => '2026-09-01 08:00:00',
        ]);

        $statusMetric = $ticket->getOrCreateStatusMetric();
        $statusMetric->update([
            'resolution_total_seconds' => 1000,
            'resolution_started_at' => null, // Giả lập bị null bất thường
        ]);

        // Tạo 1 group timer active từ 2026-09-01 10:00:00
        TicketGroupMetric::create([
            'ticket_id' => $ticket->ticket_id,
            'layer' => 'L1',
            'group_id' => 101,
            'total_seconds' => 7200,
            'used_seconds' => 0,
            'started_at' => Carbon::parse('2026-09-01 10:00:00'),
        ]);

        $timerService = app(TimerService::class);
        $endedAt = Carbon::parse('2026-09-01 11:00:00'); // Trôi qua 3600s

        $timerService->finalizeResolutionTime($ticket, $statusMetric, $endedAt);

        $this->assertSame(4600, $statusMetric->resolution_total_seconds, 'Phải cứu được 3600s từ group timer active fallback');
        $this->assertNull($statusMetric->resolution_started_at);
    }

    public function test_due_driven_reopen_does_not_add_closed_duration_to_resolution_time(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 77777,
            'status' => 'Open',
            'priority' => 'Medium',
            'ticket_type' => 'Default',
            'group_id' => 101,
            'fd_created_at' => '2026-09-01 08:00:00',
        ]);
        $ticket->getOrCreateTtrMetric()->update(['processing_mode' => 'due-driven']);

        $handler = app(StatusChangedHandler::class);

        // Đóng vé lúc 09:00:00 (chạy 3600s)
        $event1 = $this->createStatusEvent($ticket, 'Open', 'Closed', Carbon::parse('2026-09-01 09:00:00'));
        $handler->handle($ticket->ticket_id, ['status' => 5], [['field' => 'status', 'old_value' => 'Open', 'new_value' => 'Closed']], $event1);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertSame(3600, $statusMetric->resolution_total_seconds);

        // Reopen vé sau 3 ngày: 2026-09-04 09:00:00
        $event2 = $this->createStatusEvent($ticket, 'Closed', 'Processing', Carbon::parse('2026-09-04 09:00:00'));
        $handler->handle($ticket->ticket_id, ['status' => 3], [['field' => 'status', 'old_value' => 'Closed', 'new_value' => 'Processing']], $event2);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        // Thời gian đóng 3 ngày KHÔNG được cộng vào resolution_total_seconds
        $this->assertSame(3600, $statusMetric->resolution_total_seconds, 'Thời gian vé đóng không được cộng vào resolution_total_seconds dù là due-driven');
        $this->assertNotNull($statusMetric->resolution_started_at);
        $this->assertSame('2026-09-04 09:00:00', Carbon::parse($statusMetric->resolution_started_at)->format('Y-m-d H:i:s'));
    }

    public function test_resolved_to_closed_transition_adds_interval_due_to_closed_precedence(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 88888,
            'status' => 'Open',
            'priority' => 'Medium',
            'ticket_type' => 'Default',
            'group_id' => 101,
            'fd_created_at' => '2026-09-01 08:00:00',
        ]);

        $handler = app(StatusChangedHandler::class);

        // Chuyển sang Resolved lúc 09:00:00 (chạy 3600s)
        $event1 = $this->createStatusEvent($ticket, 'Open', 'Resolved', Carbon::parse('2026-09-01 09:00:00'));
        $handler->handle($ticket->ticket_id, ['status' => 4], [['field' => 'status', 'old_value' => 'Open', 'new_value' => 'Resolved']], $event1);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        $this->assertSame(3600, $statusMetric->resolution_total_seconds);

        // Chuyển Resolved -> Closed lúc 10:30:00 (khoảng cách 5400s)
        $event2 = $this->createStatusEvent($ticket, 'Resolved', 'Closed', Carbon::parse('2026-09-01 10:30:00'));
        $handler->handle($ticket->ticket_id, ['status' => 5], [['field' => 'status', 'old_value' => 'Resolved', 'new_value' => 'Closed']], $event2);

        $statusMetric = $ticket->fresh()->getOrCreateStatusMetric();
        // Theo Closed precedence (BRD BR-STA-02), khoảng Resolved -> Closed được cộng vào Resolution Time
        $this->assertSame(3600 + 5400, $statusMetric->resolution_total_seconds, 'Khoảng Resolved -> Closed phải được cộng vào Resolution Time');
        $this->assertNull($statusMetric->resolution_started_at);
    }

    private function createStatusEvent(Ticket $ticket, string $oldStatus, string $newStatus, Carbon $timestamp): TicketEvent
    {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', $ticket->ticket_id . '_' . $oldStatus . '_' . $newStatus . '_' . $timestamp->timestamp),
            'event_type' => TicketEvent::EVENT_STATUS_CHANGED,
            'event_data' => [
                'ticket_data' => [
                    'status' => $newStatus,
                ],
            ],
            'field_changes' => [
                [
                    'field' => 'status',
                    'old_value' => $oldStatus,
                    'new_value' => $newStatus,
                ],
            ],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => $timestamp->toIso8601String(),
            'received_at' => $timestamp,
        ]);
    }
}
