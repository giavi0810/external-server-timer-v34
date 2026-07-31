<?php

namespace App\Console\Commands;

use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Console\Command;

class RecoverFreshdeskSpoolCommand extends Command
{
    protected $signature = 'freshdesk-spool:recover {--limit=500}';
    protected $description = 'Recover expired Freshdesk spool delivery leases';

    public function handle(DurableWebhookSpool $spool): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $enqueued = $spool->recoverExpired('enqueued', $limit);
        $processing = $spool->recoverExpired('processing', $limit);
        $this->info("Recovered enqueued={$enqueued}, processing={$processing}.");
        return self::SUCCESS;
    }
}
