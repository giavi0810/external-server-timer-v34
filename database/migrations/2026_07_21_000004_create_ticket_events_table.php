<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->string('idempotency_key')->unique();
            $table->string('event_type', 100);
            $table->jsonb('event_data');
            $table->jsonb('field_changes')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('event_timestamp')->index();
            $table->timestampTz('received_at')->index();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('processed_at')->nullable();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->unique(['id', 'ticket_id'], 'ticket_events_id_ticket_unique');
            $table->index(['ticket_id', 'event_timestamp'], 'ticket_event_time_index');
            $table->index(['status', 'locked_at'], 'ticket_event_queue_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_events ADD CONSTRAINT ticket_events_status_check CHECK (status IN ('pending', 'queued', 'processing', 'processed', 'failed'))");
            DB::statement('ALTER TABLE ticket_events ADD CONSTRAINT ticket_events_attempt_count_check CHECK (attempt_count >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
    }
};
