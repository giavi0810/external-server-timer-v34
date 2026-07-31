<?php

namespace App\Console\Commands;

use App\Models\RocketChatDeliveryStatus;
use Illuminate\Console\Command;

class PruneRocketChatDeliveryStatusesCommand extends Command
{
    protected $signature = 'rocketchat-audit:prune';

    protected $description = 'Delete expired RocketChat delivery status records';

    public function handle(): int
    {
        $retentionDays = max(1, (int) config('rocketchat_audit.retention_days', 90));
        $cutoff = now()->utc()->subDays($retentionDays);
        $deleted = 0;

        do {
            $ids = RocketChatDeliveryStatus::query()
                ->where('attempted_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');

            $count = $ids->isEmpty()
                ? 0
                : RocketChatDeliveryStatus::query()->whereKey($ids)->delete();
            $deleted += $count;
        } while ($count === 1000);

        $this->info("Deleted {$deleted} expired RocketChat delivery status record(s).");

        return self::SUCCESS;
    }
}
