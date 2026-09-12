<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * TimelineService — Ghi log sự kiện vào ticket_histories.
 */
class TimelineService
{
    /**
     * Ghi log sự kiện vào bảng lịch sử.
     */
    public function appendTicketEventLog(
        Ticket $ticket,
        string $key,
        mixed $value,
        ?string $timestamp = null,
        ?string $label = null,
        ?TicketEvent $sourceEvent = null
    ): void {
        $historyValue = $value;
        if ($historyValue instanceof Carbon) {
            $historyValue = $historyValue->format('Y-m-d\TH:i:s\Z');
        } elseif (is_array($historyValue) || is_object($historyValue)) {
            $historyValue = json_encode($historyValue);
        } else {
            $historyValue = (string) $historyValue;
        }

        $historyOccurrence = Carbon::parse($timestamp ?? now());

        $sourceEvent ??= TicketEvent::where('ticket_id', $ticket->ticket_id)
            ->where('event_timestamp', $historyOccurrence)
            ->latest('id')
            ->first()
                ?? TicketEvent::where('ticket_id', $ticket->ticket_id)
                    ->where('event_timestamp', '<=', $historyOccurrence)
                    ->latest('event_timestamp')
                    ->latest('id')
                    ->first();

        if (! $sourceEvent) {
            Log::warning('Ticket history skipped because no source event exists', [
                'ticket_id' => $ticket->ticket_id,
                'key' => $key,
            ]);

            return;
        }

        TicketHistory::firstOrCreate(
            [
                'ticket_event_id' => $sourceEvent->id,
                'event_key' => $key,
            ],
            [
                'ticket_id' => $ticket->ticket_id,
                'event_value' => $historyValue,
                'label' => $label,
                'occurred_at' => $historyOccurrence,
                'created_at' => $historyOccurrence,
                'updated_at' => now(),
            ]
        );

        Log::debug('TicketEvent history logged to History Table', [
            'ticket_id' => $ticket->ticket_id,
            'ticket_event_id' => $sourceEvent->id,
            'key' => $key,
        ]);
    }
}
