<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_ttr_metrics', function (Blueprint $table) {
            $table->bigInteger('ticket_id')->primary();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedInteger('used_seconds')->default(0);
            $table->string('processing_mode', 50)->default('priority-driven');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('original_due_date_ttr')->nullable();
            $table->timestampTz('latest_due_date_ttr')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_ttr_metrics ADD CONSTRAINT ticket_ttr_metrics_processing_mode_check CHECK (processing_mode IN ('priority-driven', 'due-driven'))");
            DB::statement('ALTER TABLE ticket_ttr_metrics ADD CONSTRAINT ticket_ttr_metrics_seconds_check CHECK (LEAST(total_seconds, used_seconds) >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_ttr_metrics');
    }
};
