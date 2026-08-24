<?php

namespace App\Console\Commands;

use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFreshdeskQuarantineCommand extends Command
{
    protected $signature = 'freshdesk-spool:retry-quarantine
        {receipt : Freshdesk spool receipt UUID}
        {--dry-run : Show the matching receipt without moving it}';

    protected $description = 'Release one quarantined Freshdesk spool receipt for a controlled retry';

    public function handle(DurableWebhookSpool $spool): int
    {
        $receiptId = strtolower(trim((string) $this->argument('receipt')));
        $path = $spool->findReceipt('quarantine', $receiptId);

        if ($path === null) {
            $this->error("Quarantined Freshdesk spool receipt {$receiptId} was not found.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info("Would release quarantined receipt {$receiptId}: ".basename($path));

            return self::SUCCESS;
        }

        $destination = $spool->releaseQuarantined($path);
        Log::notice('Quarantined Freshdesk spool receipt released for controlled retry', [
            'receipt_id' => $receiptId,
            'destination' => basename($destination),
        ]);
        $this->info("Released quarantined receipt {$receiptId} for retry.");

        return self::SUCCESS;
    }
}
