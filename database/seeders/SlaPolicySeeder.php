<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('data/sla_policies.json');
        
        if (!file_exists($jsonPath)) {
            $this->command->error("SLA config file NOT found at: {$jsonPath}");
            return;
        }

        $jsonContent = file_get_contents($jsonPath);
        $slaConfig = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error("Invalid JSON format in sla_policies.json: " . json_last_error_msg());
            return;
        }

        foreach ($slaConfig as $ticketType => $priorities) {
            foreach ($priorities as $priority => $times) {
                DB::table('sla_policies')->updateOrInsert(
                    ['ticket_type' => $ticketType, 'priority' => $priority, 'version' => 1],
                    [
                        'total_seconds' => $times['total'] * 3600, // Convert hours to seconds
                        'l1_seconds' => $times['L1'] * 3600,
                        'l2_seconds' => $times['L2'] * 3600,
                        'l3_seconds' => $times['L3'] * 3600,
                        'l4_seconds' => $times['L4'] * 3600,
                        'rt_seconds' => $times['RT'] * 3600,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        $this->command->info('SLA configurations from JSON seeded/updated successfully!');
    }
}
