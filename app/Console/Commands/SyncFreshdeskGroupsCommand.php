<?php

namespace App\Console\Commands;

use App\Services\FreshdeskApiService;
use Illuminate\Console\Command;
use Throwable;

class SyncFreshdeskGroupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freshdesk:sync-groups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all Freshdesk groups from API into freshdesk_groups database table';

    /**
     * Execute the console command.
     */
    public function handle(FreshdeskApiService $freshdeskApiService): int
    {
        $this->info('Starting Freshdesk groups synchronization...');

        try {
            $freshdeskApiService->refreshGroupMappings();
            $this->info('Freshdesk groups synchronized successfully!');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to sync Freshdesk groups: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
