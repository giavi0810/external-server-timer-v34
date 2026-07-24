<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('freshdesk_groups')
            ->where('is_active', false)
            ->where('is_default_assignment', true)
            ->update([
                'is_default_assignment' => false,
                'updated_at' => now(),
            ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE freshdesk_groups
                ADD CONSTRAINT freshdesk_groups_default_active_check
                CHECK (NOT is_default_assignment OR is_active)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freshdesk_groups');
    }
};
