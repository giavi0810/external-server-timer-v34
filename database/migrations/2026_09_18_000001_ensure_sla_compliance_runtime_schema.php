<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tickets', 'final_sla_compliance')
            && ! Schema::hasColumn('tickets', 'final_sla_compliant')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->renameColumn('final_sla_compliance', 'final_sla_compliant');
            });
        }

        $this->addTicketComplianceColumns();
        $this->addScannerIndexes();
        $this->addMetricConstraint();
    }

    public function down(): void
    {
        // Forward-only compatibility migration. The base V34 migrations own
        // these columns and indexes on a fresh installation.
    }

    private function addTicketComplianceColumns(): void
    {
        if (! Schema::hasColumn('tickets', 'sla_violated')) {
            Schema::table('tickets', fn (Blueprint $table) => $table
                ->boolean('sla_violated')->default(false));
        }

        if (! Schema::hasColumn('tickets', 'sla_violated_at')) {
            Schema::table('tickets', fn (Blueprint $table) => $table
                ->timestampTz('sla_violated_at')->nullable());
        }

        if (! Schema::hasColumn('tickets', 'sla_violation_metric')) {
            Schema::table('tickets', fn (Blueprint $table) => $table
                ->string('sla_violation_metric', 10)->nullable());
        }

        if (! Schema::hasColumn('tickets', 'final_sla_compliant')) {
            Schema::table('tickets', fn (Blueprint $table) => $table
                ->boolean('final_sla_compliant')->nullable());
        }
    }

    private function addScannerIndexes(): void
    {
        $driver = DB::getDriverName();

        if (! $this->hasIndex('ticket_ttr_metrics', 'ticket_ttr_overdue_scan_index')) {
            Schema::table('ticket_ttr_metrics', function (Blueprint $table): void {
                $table->index(
                    ['processing_mode', 'latest_due_date_ttr', 'ticket_id'],
                    'ticket_ttr_overdue_scan_index'
                );
            });
        }

        if ($this->hasIndex('ticket_first_response_metrics', 'ticket_rt_overdue_scan_index')) {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE INDEX IF NOT EXISTS ticket_rt_overdue_scan_index
                ON ticket_first_response_metrics (latest_due_date_rt, ticket_id)
                WHERE status = 'running' AND first_response_at IS NULL
                SQL);

            return;
        }

        Schema::table('ticket_first_response_metrics', function (Blueprint $table): void {
            $table->index(
                ['status', 'latest_due_date_rt', 'ticket_id'],
                'ticket_rt_overdue_scan_index'
            );
        });
    }

    private function addMetricConstraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'tickets_sla_violation_metric_check'
                ) THEN
                    ALTER TABLE tickets
                    ADD CONSTRAINT tickets_sla_violation_metric_check
                    CHECK (sla_violation_metric IS NULL OR sla_violation_metric IN ('rt', 'ttr', 'both'));
                END IF;
            END
            $$
            SQL);
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
