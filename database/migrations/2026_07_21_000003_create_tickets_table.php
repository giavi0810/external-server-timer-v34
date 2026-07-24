<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->bigInteger('ticket_id')->primary();
            $table->bigInteger('source_ticket_id')->nullable();
            $table->string('creation_reason', 100)->default('freshdesk_created');
            $table->string('subject')->nullable();
            $table->string('status', 50)->index();
            $table->string('priority', 50)->index();
            $table->string('ticket_type', 100)->nullable()->index();
            $table->string('group_id', 50)->nullable();
            $table->bigInteger('requester_id')->nullable();
            $table->timestampTz('fd_created_at')->index();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampsTz();

            $table->foreign('group_id')->references('group_id')->on('freshdesk_groups')->nullOnDelete();
            $table->index(['group_id', 'status'], 'ticket_group_status_index');
            $table->index(['ticket_type', 'priority'], 'ticket_type_priority_index');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('source_ticket_id')->references('ticket_id')->on('tickets')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_creation_reason_check CHECK (creation_reason IN ('freshdesk_created', 'requester_reply_after_7_days'))");
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IN ('Urgent', 'High', 'Medium', 'Low'))");
            DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_source_check CHECK (source_ticket_id IS NULL OR source_ticket_id <> ticket_id)');
            DB::statement('CREATE INDEX tickets_source_ticket_id_index ON tickets (source_ticket_id) WHERE source_ticket_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
