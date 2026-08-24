<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_due_date_changed', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->unsignedBigInteger('ticket_sla_stage_id');
            $table->unsignedInteger('change_number');
            $table->timestampTz('old_due_at');
            $table->timestampTz('new_due_at');
            $table->string('processing_phase', 100);
            $table->string('reason_code', 100);
            $table->text('reason_detail')->nullable();
            $table->bigInteger('agent_id');
            $table->string('agent_name');
            $table->timestampTz('submitted_at')->index();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->foreign(['ticket_sla_stage_id', 'ticket_id'], 'ticket_due_date_changed_stage_foreign')
                ->references(['id', 'ticket_id'])->on('ticket_sla_stages')->cascadeOnDelete();
            $table->unique(['ticket_id', 'change_number'], 'ticket_due_date_changed_number_unique');
            $table->unique('ticket_sla_stage_id', 'ticket_due_date_changed_stage_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ticket_due_date_changed ADD CONSTRAINT ticket_due_date_changed_number_check CHECK (change_number > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_due_date_changed');
    }
};
