<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\Sla\AppTimerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AppTimerSyncDurationFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_freshdesk_duration_fields_are_second_strings(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 91001,
            'status' => 'Closed',
            'priority' => 'High',
            'fd_created_at' => '2026-08-29T00:00:00Z',
            'closed_at' => '2026-08-29T01:00:00Z',
        ]);

        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => 43_920,
            'used_seconds' => 0,
            'status' => 'ended_replied',
            'first_response_at' => '2026-08-29T00:01:00Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => 43_920,
            'used_seconds' => 47_520,
        ]);
        $ticket->getOrCreateGroupMetric('L1')->update([
            'total_seconds' => 43_920,
            'used_seconds' => 3_661,
        ]);

        $fields = $this->buildSlaCustomFields($ticket->fresh());

        $this->assertSame('43920', $fields['cf_rt_time']);
        $this->assertSame('-3600', $fields['cf_ttr_time']);
        $this->assertSame('43920', $fields['cf__l1_time_allowed']);
        $this->assertSame('3661', $fields['cf__l1_time_actual']);

        foreach (['l2', 'l3', 'l4'] as $prefix) {
            $this->assertSame('0', $fields["cf__{$prefix}_time_allowed"]);
            $this->assertSame('0', $fields["cf__{$prefix}_time_actual"]);
        }

        foreach ([
            'cf_rt_time',
            'cf_ttr_time',
            'cf__l1_time_allowed',
            'cf__l1_time_actual',
            'cf__l2_time_allowed',
            'cf__l2_time_actual',
            'cf__l3_time_allowed',
            'cf__l3_time_actual',
            'cf__l4_time_allowed',
            'cf__l4_time_actual',
        ] as $field) {
            $this->assertIsString($fields[$field]);
        }
    }

    private function buildSlaCustomFields(Ticket $ticket): array
    {
        $method = new ReflectionMethod(AppTimerSyncService::class, 'buildSlaCustomFields');

        return $method->invoke(app(AppTimerSyncService::class), $ticket);
    }
}
