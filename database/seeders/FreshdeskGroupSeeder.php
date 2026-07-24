<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FreshdeskGroupSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = config('freshdesk.group_mapping', []);
        $layers = config('freshdesk.group_layers', []);
        $defaultAssigned = false;

        foreach ($mapping as $groupId => $name) {
            $layer = $layers[$name] ?? null;
            if (!in_array($layer, ['L1', 'L2', 'L3', 'L4'], true)) {
                continue;
            }

            $isDefault = !$defaultAssigned && $layer === 'L1';
            DB::table('freshdesk_groups')->updateOrInsert(
                ['group_id' => (string) $groupId],
                [
                    'name' => $name,
                    'main_layer' => $layer,
                    'is_active' => true,
                    'is_default_assignment' => $isDefault,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $defaultAssigned = $defaultAssigned || $isDefault;
        }
    }
}
