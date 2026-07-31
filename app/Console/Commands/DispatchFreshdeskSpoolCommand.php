<?php

namespace App\Console\Commands;

use App\Jobs\PersistFreshdeskWebhookJob;
use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchFreshdeskSpoolCommand extends Command
{
    protected $signature = 'freshdesk-spool:dispatch {--limit=}';
    protected $description = 'Dispatch durable Freshdesk webhook receipts to Redis';

    public function handle(DurableWebhookSpool $spool): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('freshdesk_spool.dispatch_batch')));
        $count = 0;

        foreach ($spool->findDueReadyFiles($limit) as $readyPath) {
            try {
                $claim = $spool->claimForDispatch($readyPath);
                try {
                    PersistFreshdeskWebhookJob::dispatch($claim['destination'], $claim['token']);
                    $count++;
                } catch (\Throwable $exception) {
                    $spool->retry($claim['destination'], $claim['token'], $exception);
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                Log::warning('Unable to dispatch Freshdesk spool receipt', [
                    'path' => $readyPath,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Dispatched {$count} Freshdesk webhook receipt(s).");
        return self::SUCCESS;
    }
}
