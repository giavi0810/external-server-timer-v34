<?php

namespace Tests\Feature;

use App\Services\RocketChatService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RedisHealthMonitorTest extends TestCase
{
    public function test_monitor_uses_the_fail_fast_health_connection(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andReturn(true);
        Redis::shouldReceive('connection')
            ->once()
            ->with('health')
            ->andReturn($connection);

        $rocketChat = $this->mock(RocketChatService::class);
        $rocketChat->shouldReceive('sendRedisRecoveredAlert')->once()->andReturn(false);

        $this->artisan('system-health:redis')->assertSuccessful();
    }

    public function test_monitor_reports_redis_failure_without_retrying_the_health_connection(): void
    {
        $exception = new \RedisException('Redis health connection timed out');
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andThrow($exception);
        Redis::shouldReceive('connection')
            ->once()
            ->with('health')
            ->andReturn($connection);

        $rocketChat = $this->mock(RocketChatService::class);
        $rocketChat->shouldReceive('sendSystemErrorAlert')
            ->once()
            ->with($exception)
            ->andReturn(false);

        $this->artisan('system-health:redis')->assertSuccessful();
    }
}
