<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sla_stage_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_sla_stage_id');
            $table->string('metric_type', 20);
            $table->unsignedInteger('sla_goal_seconds');
            $table->unsignedInteger('used_before_seconds')->default(0);
            $table->unsignedInteger('used_at_checkpoint_seconds')->nullable();
            $table->unsignedInteger('effective_sla_seconds');
            $table->unsignedInteger('extra_time_granted_seconds')->default(0);
            $table->string('eligibility_status', 50)->default('not_applicable');
            $table->timestampTz('old_due_at')->nullable();
            $table->timestampTz('standard_due_at')->nullable();
            $table->timestampTz('adjusted_due_at')->nullable();
            $table->string('metric_result', 50)->default('pending')->index();
            $table->string('result_reason')->nullable();
            $table->timestampTz('overdue_at')->nullable();
            $table->string('overdue_owner_group_id', 50)->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_sla_stage_id')->references('id')->on('ticket_sla_stages')->cascadeOnDelete();
            $table->foreign('overdue_owner_group_id')->references('group_id')->on('freshdesk_groups')->nullOnDelete();
            $table->unique(['ticket_sla_stage_id', 'metric_type'], 'sla_stage_metric_type_unique');
            $table->index(['overdue_owner_group_id', 'overdue_at'], 'sla_stage_overdue_owner_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_sla_stage_metrics ADD CONSTRAINT ticket_sla_stage_metrics_type_check CHECK (metric_type IN ('ttr', 'rt'))");
            DB::statement("ALTER TABLE ticket_sla_stage_metrics ADD CONSTRAINT ticket_sla_stage_metrics_eligibility_check CHECK (eligibility_status IN ('eligible', 'ineligible', 'not_applicable'))");
            DB::statement("ALTER TABLE ticket_sla_stage_metrics ADD CONSTRAINT ticket_sla_stage_metrics_result_check CHECK (metric_result IN ('pending', 'pass', 'fail', 'not_applicable'))");
            DB::statement('ALTER TABLE ticket_sla_stage_metrics ADD CONSTRAINT ticket_sla_stage_metrics_seconds_check CHECK (LEAST(sla_goal_seconds, used_before_seconds, effective_sla_seconds, extra_time_granted_seconds) >= 0 AND (used_at_checkpoint_seconds IS NULL OR used_at_checkpoint_seconds >= 0))');
            DB::statement("ALTER TABLE ticket_sla_stage_metrics ADD CONSTRAINT ticket_sla_stage_metrics_overdue_result_check CHECK ((metric_result = 'fail') = (overdue_at IS NOT NULL))");
            DB::statement("ALTER TABLE ticket_sla_stage_metrics ADD CONSTRAINT ticket_sla_stage_metrics_overdue_owner_check CHECK (metric_result = 'fail' OR overdue_owner_group_id IS NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_stage_metrics');
    }
};
