<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freshdesk_groups', function (Blueprint $table) {
            $table->string('group_id', 50)->primary();
            $table->string('name');
            $table->string('main_layer', 20)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default_assignment')->default(false)->index();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE freshdesk_groups ADD CONSTRAINT freshdesk_groups_main_layer_check CHECK (main_layer IN ('L1', 'L2', 'L3', 'L4'))");
            DB::statement("ALTER TABLE freshdesk_groups ADD CONSTRAINT freshdesk_groups_default_layer_check CHECK (NOT is_default_assignment OR main_layer = 'L1')");
            DB::statement('CREATE UNIQUE INDEX freshdesk_groups_one_default_unique ON freshdesk_groups (is_default_assignment) WHERE is_default_assignment');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freshdesk_groups');
    }
};
