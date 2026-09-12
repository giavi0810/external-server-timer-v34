<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_ttr_metrics', function (Blueprint $table) {
            $table->timestampTz('mode_switched_at')
                ->nullable()
                ->after('started_at')
                ->comment('Thoi diem chuyen tu priority-driven sang due-driven');
            $table->unsignedInteger('used_seconds_at_mode_switch')
                ->nullable()
                ->after('mode_switched_at')
                ->comment('TTR da su dung theo priority-driven tai thoi diem chuyen mode');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_ttr_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'mode_switched_at',
                'used_seconds_at_mode_switch',
            ]);
        });
    }
};
