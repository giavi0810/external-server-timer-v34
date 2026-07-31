<?php

namespace App\Console\Commands;

use App\Services\RocketChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class MonitorRedisHealthCommand extends Command
{
    protected $signature = 'system-health:redis';

    protected $description = 'Monitor Redis and send RocketChat DOWN/RECOVERED state transitions';

    public function handle(RocketChatService $rocketChat): int
    {
        if (! config('services.rocketchat.redis_monitor_enabled', true)) {
            return self::SUCCESS;
        }

        try {
            Redis::connection()->ping();
            $rocketChat->sendRedisRecoveredAlert();
        } catch (Throwable $exception) {
            Log::warning('Redis health check failed', [
                'error' => $exception->getMessage(),
            ]);
            $rocketChat->sendSystemErrorAlert($exception);
        }

        return self::SUCCESS;
    }
}
