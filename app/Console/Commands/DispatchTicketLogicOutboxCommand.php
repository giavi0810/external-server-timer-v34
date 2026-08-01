<?php

namespace App\Console\Commands;

use App\Services\Queue\TicketLogicOutboxDispatcher;
use Illuminate\Console\Command;

class DispatchTicketLogicOutboxCommand extends Command
{
    protected $signature = 'ticket-logic-outbox:dispatch {--limit=250}';

    protected $description = 'Claim PostgreSQL logic outbox rows and dispatch Redis jobs';

    public function handle(TicketLogicOutboxDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $dispatcher->dispatch($limit);

        $this->info("Dispatched {$result->dispatched} ticket logic job(s).");

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
