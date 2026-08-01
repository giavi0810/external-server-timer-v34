<?php

namespace App\Services\Queue;

use App\Models\FreshdeskOutboundOperation;
use App\Models\TicketEvent;
use App\Models\TicketLogicOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreshdeskOutboundService
{
    public function enqueueForEvent(
        TicketEvent $event,
        string $operationType,
        string $coalesceKey,
        array $payload
    ): FreshdeskOutboundOperation {
        $outbox = TicketLogicOutbox::query()
            ->where('ticket_id', $event->ticket_id)
            ->lockForUpdate()
            ->firstOrFail();
        $generation = $event->logic_generation ?? $outbox->requested_generation;
        // Business side effects belong to the immutable source event. Replay may
        // recalculate SLA generations, but it must never recreate the remote action.
        $idempotencyKey = implode(':', [$operationType, $event->id]);

        return FreshdeskOutboundOperation::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'operation_id' => (string) Str::uuid(),
                'ticket_id' => $event->ticket_id,
                'operation_type' => $operationType,
                'coalesce_key' => $coalesceKey,
                'generation' => $generation,
                'sync_epoch' => $outbox->sync_epoch,
                'operation_version' => 1,
                'payload' => $payload,
                'state' => 'ready',
                'available_at' => now(),
            ]
        );
    }

    public function enqueueCommand(
        int $ticketId,
        string $operationType,
        string $idempotencyKey,
        array $payload
    ): FreshdeskOutboundOperation {
        return DB::transaction(function () use (
            $ticketId,
            $operationType,
            $idempotencyKey,
            $payload
        ): FreshdeskOutboundOperation {
            $outbox = TicketLogicOutbox::query()
                ->where('ticket_id', $ticketId)
                ->lockForUpdate()
                ->first();

            return FreshdeskOutboundOperation::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'operation_id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'operation_type' => $operationType,
                    'coalesce_key' => $operationType,
                    'generation' => (int) ($outbox?->requested_generation ?? 0),
                    'sync_epoch' => (int) ($outbox?->sync_epoch ?? 0),
                    'operation_version' => 1,
                    'payload' => $payload,
                    'state' => 'ready',
                    'available_at' => now(),
                ]
            );
        });
    }
}
