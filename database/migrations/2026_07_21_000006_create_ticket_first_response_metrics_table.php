<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_first_response_metrics', function (Blueprint $table) {
            $table->bigInteger('ticket_id')->primary();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedInteger('used_seconds')->default(0);
            $table->string('status', 50)->default('running')->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('original_due_date_rt')->nullable();
            $table->timestampTz('lastest_due_date_rt')->nullable();
            $table->timestampTz('first_response_at')->nullable();
            $table->unsignedInteger('agent_reply_count')->default(0);
            $table->unsignedInteger('requester_reply_count')->default(0);
            $table->timestampTz('agent_responded_at')->nullable();
            $table->timestampTz('requester_responded_at')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_first_response_metrics ADD CONSTRAINT ticket_first_response_status_check CHECK (status IN ('running', 'paused', 'ended_replied', 'ended_closed_no_reply'))");
            DB::statement('ALTER TABLE ticket_first_response_metrics ADD CONSTRAINT ticket_first_response_counts_check CHECK (LEAST(total_seconds, used_seconds, agent_reply_count, requester_reply_count) >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_first_response_metrics');
    }
};
