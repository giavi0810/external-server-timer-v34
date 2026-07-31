<?php

namespace App\Services\Queue;

use App\Models\FreshdeskOutboundOperation;
use App\Models\TicketEvent;
use App\Models\TicketLogicOutbox;
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
}
