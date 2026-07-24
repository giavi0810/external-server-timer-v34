<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_histories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->unsignedBigInteger('ticket_event_id');
            $table->string('event_key', 100);
            $table->text('event_value')->nullable();
            $table->string('label')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->foreign(['ticket_event_id', 'ticket_id'], 'ticket_histories_event_foreign')
                ->references(['id', 'ticket_id'])->on('ticket_events');
            $table->index(['ticket_id', 'occurred_at'], 'ticket_history_time_index');
            $table->index(['ticket_id', 'event_key'], 'ticket_history_event_index');
            $table->index('ticket_event_id', 'ticket_histories_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_histories');
    }
};
