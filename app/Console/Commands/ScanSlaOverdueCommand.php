<?php

namespace App\Console\Commands;

use App\Services\Sla\OverdueSyncScanner;
use Illuminate\Console\Command;

class ScanSlaOverdueCommand extends Command
{
    protected $signature = 'sla-overdue:scan {--limit=500 : Maximum tickets per metric type}';

    protected $description = 'Queue Freshdesk SLA syncs for RT/TTR deadlines that have just become overdue';

    public function handle(OverdueSyncScanner $scanner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $scanner->scan($limit);

        $this->info("Queued overdue SLA syncs: TTR={$result['ttr']}, RT={$result['rt']}");

        return self::SUCCESS;
    }
}
