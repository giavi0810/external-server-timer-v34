<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM ticket_histories
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM ticket_histories
                GROUP BY ticket_event_id, event_key
            )
            SQL);

        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->unique(
                ['ticket_event_id', 'event_key'],
                'ticket_histories_event_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->dropUnique('ticket_histories_event_key_unique');
        });
    }
};
