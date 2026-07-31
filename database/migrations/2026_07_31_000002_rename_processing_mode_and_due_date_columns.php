<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameIfPresent('ticket_ttr_metrics', 'sla_mode', 'processing_mode');
        $this->renameIfPresent('ticket_ttr_metrics', 'lastest_due_date_ttr', 'latest_due_date_ttr');
        $this->renameIfPresent('ticket_first_response_metrics', 'lastest_due_date_rt', 'latest_due_date_rt');
        $this->renameIfPresent('ticket_sla_stages', 'sla_mode', 'processing_mode');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ticket_ttr_metrics DROP CONSTRAINT IF EXISTS ticket_ttr_metrics_sla_mode_check');
            DB::statement('ALTER TABLE ticket_ttr_metrics DROP CONSTRAINT IF EXISTS ticket_ttr_metrics_processing_mode_check');
            DB::statement(
                "ALTER TABLE ticket_ttr_metrics ADD CONSTRAINT ticket_ttr_metrics_processing_mode_check "
                ."CHECK (processing_mode IN ('priority-driven', 'due-driven'))"
            );

            DB::statement('ALTER TABLE ticket_sla_stages DROP CONSTRAINT IF EXISTS ticket_sla_stages_mode_check');
            DB::statement(
                "ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_mode_check "
                ."CHECK (processing_mode IN ('priority-driven', 'due-driven'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ticket_ttr_metrics DROP CONSTRAINT IF EXISTS ticket_ttr_metrics_processing_mode_check');
            DB::statement('ALTER TABLE ticket_sla_stages DROP CONSTRAINT IF EXISTS ticket_sla_stages_mode_check');
        }

        $this->renameIfPresent('ticket_ttr_metrics', 'processing_mode', 'sla_mode');
        $this->renameIfPresent('ticket_ttr_metrics', 'latest_due_date_ttr', 'lastest_due_date_ttr');
        $this->renameIfPresent('ticket_first_response_metrics', 'latest_due_date_rt', 'lastest_due_date_rt');
        $this->renameIfPresent('ticket_sla_stages', 'processing_mode', 'sla_mode');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE ticket_ttr_metrics ADD CONSTRAINT ticket_ttr_metrics_sla_mode_check "
                ."CHECK (sla_mode IN ('priority-driven', 'due-driven'))"
            );
            DB::statement(
                "ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_mode_check "
                ."CHECK (sla_mode IN ('priority-driven', 'due-driven'))"
            );
        }
    }

    private function renameIfPresent(string $table, string $from, string $to): void
    {
        if (!Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($from, $to): void {
            $blueprint->renameColumn($from, $to);
        });
    }
};
