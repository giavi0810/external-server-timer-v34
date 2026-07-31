<?php

namespace App\Console\Commands;

use App\Models\TicketEvent;
use Illuminate\Console\Command;

class RecoverTicketEventProcessingCommand extends Command
{
    protected $signature = 'ticket-events:recover-processing {--limit=500} {--lease-seconds=180}';
    protected $description = 'Return ticket events with expired processing leases to pending';

    public function handle(): int
    {
        $ids = TicketEvent::query()
            ->where('status', TicketEvent::STATUS_PROCESSING)
            ->where('locked_at', '<=', now()->subSeconds(max(30, (int) $this->option('lease-seconds'))))
            ->orderBy('locked_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');
        $count = 0;

        foreach ($ids as $id) {
            $count += TicketEvent::query()
                ->whereKey($id)
                ->where('status', TicketEvent::STATUS_PROCESSING)
                ->where('locked_at', '<=', now()->subSeconds(max(30, (int) $this->option('lease-seconds'))))
                ->update([
                    'status' => TicketEvent::STATUS_PENDING,
                    'locked_at' => null,
                    'processing_token' => null,
                    'last_error' => 'Recovered expired processing lease.',
                ]);
        }

        $this->info("Recovered {$count} ticket event(s).");
        return self::SUCCESS;
    }
}
