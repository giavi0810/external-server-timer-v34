<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sla_stages', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->unsignedBigInteger('sla_policy_id');
            $table->unsignedInteger('sequence_number');
            $table->unsignedInteger('priority_stage_number')->nullable();
            $table->string('trigger_type', 50);
            $table->string('priority', 50);
            $table->string('processing_mode', 50);
            $table->timestampTz('opened_at');
            $table->timestampTz('checkpoint_at')->nullable();
            $table->unsignedBigInteger('opened_by_event_id');
            $table->unsignedBigInteger('checkpoint_event_id')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->foreign('sla_policy_id')->references('id')->on('sla_policies');
            $table->foreign(['opened_by_event_id', 'ticket_id'], 'ticket_sla_stages_open_event_foreign')
                ->references(['id', 'ticket_id'])->on('ticket_events');
            $table->foreign(['checkpoint_event_id', 'ticket_id'], 'ticket_sla_stages_checkpoint_event_foreign')
                ->references(['id', 'ticket_id'])->on('ticket_events');
            $table->unique(['id', 'ticket_id'], 'ticket_sla_stages_id_ticket_unique');
            $table->unique(['ticket_id', 'sequence_number'], 'ticket_sla_stage_sequence_unique');
            $table->unique(['ticket_id', 'priority_stage_number'], 'ticket_priority_stage_unique');
            $table->index(['ticket_id', 'checkpoint_at'], 'ticket_sla_stage_open_index');
            $table->index('sla_policy_id', 'ticket_sla_stages_policy_index');
            $table->index('opened_by_event_id', 'ticket_sla_stages_open_event_index');
            $table->index('checkpoint_event_id', 'ticket_sla_stages_checkpoint_event_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_trigger_check CHECK (trigger_type IN ('initial', 'priority_change', 'due_date_change', 'reopen'))");
            DB::statement("ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_priority_check CHECK (priority IN ('Urgent', 'High', 'Medium', 'Low'))");
            DB::statement("ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_mode_check CHECK (processing_mode IN ('priority-driven', 'due-driven'))");
            DB::statement('ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_number_check CHECK (sequence_number > 0 AND (priority_stage_number IS NULL OR priority_stage_number > 0))');
            DB::statement('ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_time_check CHECK (checkpoint_at IS NULL OR checkpoint_at >= opened_at)');
            DB::statement('ALTER TABLE ticket_sla_stages ADD CONSTRAINT ticket_sla_stages_checkpoint_event_check CHECK ((checkpoint_at IS NULL) = (checkpoint_event_id IS NULL))');
            DB::statement('CREATE UNIQUE INDEX ticket_sla_stages_one_open_unique ON ticket_sla_stages (ticket_id) WHERE checkpoint_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_stages');
    }
};
