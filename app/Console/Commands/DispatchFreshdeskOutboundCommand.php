<?php

namespace App\Console\Commands;

use App\Services\Queue\FreshdeskOutboundDispatcher;
use Illuminate\Console\Command;

class DispatchFreshdeskOutboundCommand extends Command
{
    protected $signature = 'freshdesk-outbound:dispatch {--limit=100}';

    protected $description = 'Dispatch ready Freshdesk outbound operations with CAS leases';

    public function handle(FreshdeskOutboundDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatch(max(1, (int) $this->option('limit')));

        $this->info("Dispatched {$result->dispatched} Freshdesk outbound operation(s).");

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
