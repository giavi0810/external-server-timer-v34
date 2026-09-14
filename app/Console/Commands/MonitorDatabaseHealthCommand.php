<?php

namespace App\Console\Commands;

use App\Services\RocketChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorDatabaseHealthCommand extends Command
{
    protected $signature = 'system-health:database';

    protected $description = 'Monitor PostgreSQL database and send RocketChat DOWN/RECOVERED state transitions';

    public function handle(RocketChatService $rocketChat): int
    {
        if (! config('services.rocketchat.database_monitor_enabled', true)) {
            return self::SUCCESS;
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

        return self::SUCCESS;
    }
}
