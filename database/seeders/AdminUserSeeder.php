<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('ADMIN_SEED_PASSWORD', env('ADMIN_PASSWORD', ''));

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('ADMIN_SEED_PASSWORD must be configured with at least 12 characters.');
        }

        AdminUser::query()->updateOrCreate(
            ['username' => (string) env('ADMIN_SEED_USERNAME', env('ADMIN_USERNAME', 'superadmin'))],
            [
                'name' => (string) env('ADMIN_SEED_NAME', 'Super Administrator'),
                'password' => $password,
                'role' => AdminUser::ROLE_SUPER_ADMIN,
                'is_active' => true,
            ],
        );

        $this->command?->info('Bootstrap super administrator is ready.');
    }
}
