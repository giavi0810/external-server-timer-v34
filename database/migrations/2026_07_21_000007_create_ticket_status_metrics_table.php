<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_status_metrics', function (Blueprint $table) {
            $table->bigInteger('ticket_id')->primary();
            $table->unsignedInteger('resolution_total_seconds')->default(0);
            $table->timestampTz('resolution_started_at')->nullable();
            $table->unsignedInteger('waiting_total_seconds')->default(0);
            $table->timestampTz('waiting_started_at')->nullable();
            $table->unsignedInteger('pending_total_seconds')->default(0);
            $table->timestampTz('pending_started_at')->nullable();
            $table->unsignedInteger('end_total_seconds')->default(0);
            $table->timestampTz('end_started_at')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ticket_status_metrics ADD CONSTRAINT ticket_status_metrics_seconds_check CHECK (LEAST(resolution_total_seconds, waiting_total_seconds, pending_total_seconds, end_total_seconds) >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_status_metrics');
    }
};
