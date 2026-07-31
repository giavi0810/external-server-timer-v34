<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rocket_chat_delivery_statuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('event_code', 100);
            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('rocketchat_message_id', 191)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('attempted_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['attempted_at', 'id'], 'rocket_chat_delivery_attempted_index');
            $table->index(
                ['status', 'attempted_at'],
                'rocket_chat_delivery_status_date_index'
            );
            $table->index(
                ['event_code', 'attempted_at'],
                'rocket_chat_delivery_event_date_index'
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE rocket_chat_delivery_statuses '
                .'ADD CONSTRAINT rocket_chat_delivery_status_check '
                ."CHECK (status IN ('pending', 'success', 'failed', 'unknown'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rocket_chat_delivery_statuses');
    }
};
