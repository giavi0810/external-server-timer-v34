<?php

namespace Database\Seeders;

use App\Services\FreshdeskApiService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

class FreshdeskGroupSeeder extends Seeder
{
    public function run(FreshdeskApiService $freshdeskApiService): void
    {
        try {
            $freshdeskApiService->refreshGroupMappings();
        } catch (Throwable $e) {
            Log::warning('FreshdeskGroupSeeder: Failed to fetch groups from Freshdesk API during seed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

