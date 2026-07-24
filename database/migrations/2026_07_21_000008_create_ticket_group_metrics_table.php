<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_group_metrics', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->string('layer', 20);
            $table->string('group_id', 50)->nullable();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedInteger('used_seconds')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->foreign('group_id')->references('group_id')->on('freshdesk_groups')->nullOnDelete();
            $table->index(['ticket_id', 'layer'], 'ticket_group_metric_layer_index');
            $table->index(['ticket_id', 'group_id'], 'ticket_group_metric_group_index');
            $table->index('group_id', 'ticket_group_metrics_group_id_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ticket_group_metrics ADD CONSTRAINT ticket_group_metrics_layer_check CHECK (layer IN ('L1', 'L2', 'L3', 'L4'))");
            DB::statement('ALTER TABLE ticket_group_metrics ADD CONSTRAINT ticket_group_metrics_seconds_check CHECK (LEAST(total_seconds, used_seconds) >= 0)');
            DB::statement('CREATE UNIQUE INDEX ticket_group_metrics_aggregate_unique ON ticket_group_metrics (ticket_id, layer) WHERE group_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX ticket_group_metrics_group_unique ON ticket_group_metrics (ticket_id, layer, group_id) WHERE group_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_group_metrics');
    }
};
