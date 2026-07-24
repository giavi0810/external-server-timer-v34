<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_type', 100);
            $table->string('priority', 50);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('total_seconds');
            $table->unsignedInteger('l1_seconds');
            $table->unsignedInteger('l2_seconds');
            $table->unsignedInteger('l3_seconds');
            $table->unsignedInteger('l4_seconds');
            $table->unsignedInteger('rt_seconds');
            $table->timestampsTz();

            $table->unique(['ticket_type', 'priority', 'version'], 'sla_policy_version_unique');
            $table->index(['ticket_type', 'priority'], 'sla_policy_lookup_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_priority_check CHECK (priority IN ('Urgent', 'High', 'Medium', 'Low'))");
            DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_version_check CHECK (version > 0)');
            DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_seconds_check CHECK (LEAST(total_seconds, l1_seconds, l2_seconds, l3_seconds, l4_seconds, rt_seconds) >= 0)');
            DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_budget_sum_check CHECK (total_seconds = l1_seconds + l2_seconds + l3_seconds + l4_seconds)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
