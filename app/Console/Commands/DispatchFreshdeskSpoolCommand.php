<?php

namespace App\Console\Commands;

use App\Services\Queue\FreshdeskSpoolDispatcher;
use Illuminate\Console\Command;

class DispatchFreshdeskSpoolCommand extends Command
{
    protected $signature = 'freshdesk-spool:dispatch {--limit=}';

    protected $description = 'Dispatch durable Freshdesk webhook receipts to Redis';

    public function handle(FreshdeskSpoolDispatcher $dispatcher): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('freshdesk_spool.dispatch_batch')));
        $result = $dispatcher->dispatch($limit);

        $this->info("Dispatched {$result->dispatched} Freshdesk webhook receipt(s).");

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
