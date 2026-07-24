<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_group_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->string('group_id', 50);
            $table->string('layer', 20);
            $table->string('status', 50);
            $table->timestampTz('from_time');
            $table->timestampTz('to_time')->nullable();
            $table->unsignedBigInteger('opened_by_event_id');
            $table->unsignedBigInteger('closed_by_event_id')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->foreign('group_id')->references('group_id')->on('freshdesk_groups');
            $table->foreign(['opened_by_event_id', 'ticket_id'], 'ticket_group_sessions_open_event_foreign')
                ->references(['id', 'ticket_id'])->on('ticket_events');
            $table->foreign(['closed_by_event_id', 'ticket_id'], 'ticket_group_sessions_close_event_foreign')
                ->references(['id', 'ticket_id'])->on('ticket_events');
            $table->index(['ticket_id', 'from_time'], 'ticket_group_session_time_index');
            $table->index(['ticket_id', 'to_time'], 'ticket_group_session_open_index');
            $table->index('group_id', 'ticket_group_sessions_group_id_index');
            $table->index('opened_by_event_id', 'ticket_group_sessions_open_event_index');
            $table->index('closed_by_event_id', 'ticket_group_sessions_close_event_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_group_sessions ADD CONSTRAINT ticket_group_sessions_layer_check CHECK (layer IN ('L1', 'L2', 'L3', 'L4'))");
            DB::statement('ALTER TABLE ticket_group_sessions ADD CONSTRAINT ticket_group_sessions_time_check CHECK (to_time IS NULL OR to_time >= from_time)');
            DB::statement('ALTER TABLE ticket_group_sessions ADD CONSTRAINT ticket_group_sessions_close_event_check CHECK ((to_time IS NULL) = (closed_by_event_id IS NULL))');
            DB::statement('CREATE UNIQUE INDEX ticket_group_sessions_one_open_unique ON ticket_group_sessions (ticket_id) WHERE to_time IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_group_sessions');
    }
};
