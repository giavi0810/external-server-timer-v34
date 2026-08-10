<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freshdesk_webhook_receipts', function (Blueprint $table) {
            $table->uuid('receipt_id')->primary();
            $table->bigInteger('ticket_id')->nullable()->index();
            $table->string('payload_checksum', 64);
            $table->timestampTz('received_at');
            $table->timestampTz('committed_at');
        });

        Schema::table('ticket_events', function (Blueprint $table) {
            $table->unsignedBigInteger('logic_generation')->nullable()->index();
            $table->string('source_order_key', 160)->nullable();
            $table->uuid('processing_token')->nullable();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestampTz('fd_updated_at')->nullable()->index();
        });

        Schema::create('ticket_logic_outboxes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id')->unique();
            $table->string('state', 40)->default('ready')->index();
            $table->string('dispatch_kind', 20)->default('normal');
            $table->unsignedBigInteger('requested_generation')->default(0);
            $table->unsignedBigInteger('acked_generation')->default(0);
            $table->unsignedBigInteger('sync_epoch')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->uuid('replay_run_id')->nullable();
            $table->timestampTz('available_at')->index();
            $table->timestampTz('visibility_at')->nullable()->index();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('freshdesk_outbound_operations', function (Blueprint $table) {
            $table->uuid('operation_id')->primary();
            $table->string('idempotency_key', 191)->unique();
            $table->bigInteger('ticket_id')->index();
            $table->string('operation_type', 60);
            $table->string('coalesce_key', 100);
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('sync_epoch');
            $table->unsignedInteger('operation_version')->default(1);
            $table->jsonb('payload')->nullable();
            $table->string('state', 30)->default('ready')->index();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('available_at')->index();
            $table->timestampTz('visibility_at')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->string('remote_id')->nullable();
            $table->boolean('reconcile_only')->default(false);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['ticket_id', 'coalesce_key', 'sync_epoch', 'generation'],
                'freshdesk_outbound_coalesce_index'
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           row_number() OVER (
                               PARTITION BY ticket_id
                               ORDER BY event_timestamp, id
                           ) AS generation
                    FROM ticket_events
                )
                UPDATE ticket_events AS events
                SET logic_generation = ranked.generation
                FROM ranked
                WHERE events.id = ranked.id
                SQL);
            DB::statement(<<<'SQL'
                INSERT INTO ticket_logic_outboxes (
                    ticket_id, state, dispatch_kind, requested_generation,
                    acked_generation, sync_epoch, available_at, created_at, updated_at
                )
                SELECT ticket_id, 'ready', 'normal', max(logic_generation), 0, 0,
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM ticket_events
                GROUP BY ticket_id
                HAVING bool_or(status IN ('pending', 'queued', 'processing'))
                ON CONFLICT (ticket_id) DO NOTHING
                SQL);
            DB::statement(<<<'SQL'
                UPDATE tickets
                SET fd_updated_at = source.latest_event_at
                FROM (
                    SELECT ticket_id, max(event_timestamp) AS latest_event_at
                    FROM ticket_events
                    GROUP BY ticket_id
                ) AS source
                WHERE tickets.ticket_id = source.ticket_id
                SQL);
        } else {
            $ticketIds = DB::table('ticket_events')->distinct()->pluck('ticket_id');
            foreach ($ticketIds as $ticketId) {
                $generation = 0;
                $hasPending = false;
                foreach (DB::table('ticket_events')->where('ticket_id', $ticketId)
                    ->orderBy('event_timestamp')->orderBy('id')->get() as $event) {
                    $generation++;
                    $hasPending = $hasPending
                        || in_array($event->status, ['pending', 'queued', 'processing'], true);
                    DB::table('ticket_events')->where('id', $event->id)
                        ->update(['logic_generation' => $generation]);
                }
                if ($hasPending) {
                    DB::table('ticket_logic_outboxes')->insertOrIgnore([
                        'ticket_id' => $ticketId,
                        'state' => 'ready',
                        'dispatch_kind' => 'normal',
                        'requested_generation' => $generation,
                        'acked_generation' => 0,
                        'sync_epoch' => 0,
                        'available_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                DB::table('tickets')->where('ticket_id', $ticketId)->update([
                    'fd_updated_at' => DB::table('ticket_events')
                        ->where('ticket_id', $ticketId)->max('event_timestamp'),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freshdesk_outbound_operations');
        Schema::dropIfExists('ticket_logic_outboxes');
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->dropColumn(['logic_generation', 'source_order_key', 'processing_token']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('fd_updated_at');
        });
        Schema::dropIfExists('freshdesk_webhook_receipts');
    }
};
