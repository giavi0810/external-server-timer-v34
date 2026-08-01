<?php

namespace Tests\Feature;

use App\Services\Queue\DispatchResult;
use App\Services\Queue\FreshdeskOutboundDispatcher;
use App\Services\Queue\FreshdeskSpoolDispatcher;
use App\Services\Queue\TicketLogicOutboxDispatcher;
use Tests\TestCase;

class DurableDispatcherCommandTest extends TestCase
{
    public function test_once_mode_dispatches_all_streams_with_configured_batch_sizes(): void
    {
        $this->mock(FreshdeskSpoolDispatcher::class)
            ->shouldReceive('dispatch')->once()->with(10)->andReturn(new DispatchResult(2));
        $this->mock(TicketLogicOutboxDispatcher::class)
            ->shouldReceive('dispatch')->once()->with(20)->andReturn(new DispatchResult(1));
        $this->mock(FreshdeskOutboundDispatcher::class)
            ->shouldReceive('dispatch')->once()->with(30)->andReturn(new DispatchResult);

        $this->artisan('durable-dispatch:work', [
            '--once' => true,
            '--spool-limit' => 10,
            '--logic-limit' => 20,
            '--outbound-limit' => 30,
        ])->assertSuccessful();
    }

    public function test_once_mode_returns_failure_when_a_stream_reports_failure(): void
    {
        $this->mock(FreshdeskSpoolDispatcher::class)
            ->shouldReceive('dispatch')->once()->andReturn(new DispatchResult(0, 1));
        $this->mock(TicketLogicOutboxDispatcher::class)
            ->shouldReceive('dispatch')->once()->andReturn(new DispatchResult);
        $this->mock(FreshdeskOutboundDispatcher::class)
            ->shouldReceive('dispatch')->once()->andReturn(new DispatchResult);

        $this->artisan('durable-dispatch:work --once')->assertFailed();
    }
}
