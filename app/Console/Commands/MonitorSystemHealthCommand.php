<?php

namespace App\Console\Commands;

use App\Services\RocketChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class MonitorSystemHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'system-health:check';

    /**
     * The console command description.
     */
    protected $description = 'Giám sát chung toàn bộ hạ tầng (Redis và PostgreSQL) và gửi thông báo DOWN/RECOVERED lên RocketChat';

    public function handle(RocketChatService $rocketChat): int
    {
        $this->checkRedisHealth($rocketChat);
        $this->checkDatabaseHealth($rocketChat);

        return self::SUCCESS;
    }

    private function checkRedisHealth(RocketChatService $rocketChat): void
    {
        if (! config('services.rocketchat.redis_monitor_enabled', true)) {
            return;
        }

        try {
            Redis::connection('health')->ping();
            $rocketChat->sendRedisRecoveredAlert();
        } catch (Throwable $exception) {
            Log::warning('Redis health check failed', [
                'error' => $exception->getMessage(),
            ]);
            $rocketChat->sendSystemErrorAlert($exception, null, 'Giám sát Redis');
        }
    }

    private function checkDatabaseHealth(RocketChatService $rocketChat): void
    {
        if (! config('services.rocketchat.database_monitor_enabled', true)) {
            return;
        }

        try {
            DB::connection()->getPdo();
            $rocketChat->sendDatabaseRecoveredAlert();
        } catch (Throwable $exception) {
            Log::warning('Database health check failed', [
                'error' => $exception->getMessage(),
            ]);
            $rocketChat->sendSystemErrorAlert($exception, null, 'Giám sát PostgreSQL');
        }
    }
}
