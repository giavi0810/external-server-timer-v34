<?php

namespace App\Console\Commands;

use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Console\Command;

class GarbageCollectFreshdeskSpoolCommand extends Command
{
    protected $signature = 'freshdesk-spool:gc {--limit=1000}';
    protected $description = 'Delete committed Freshdesk spool receipts after the safety window';

    public function handle(DurableWebhookSpool $spool): int
    {
        $count = $spool->collectGarbage(max(1, (int) $this->option('limit')));
        $this->info("Deleted {$count} committed Freshdesk spool receipt(s).");
        return self::SUCCESS;
    }
}
