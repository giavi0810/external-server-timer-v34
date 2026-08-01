<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketEvent;
use App\Services\FreshdeskApiService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * TimelineService — Ghi log sự kiện vào ticket_histories và đồng bộ với Freshdesk.
 */
class TimelineService
{
    protected ?FreshdeskApiService $freshdeskService;

    public function __construct(?FreshdeskApiService $freshdeskService = null)
    {
        $this->freshdeskService = $freshdeskService;
    }

    /**
     * Ghi log sự kiện vào bảng lịch sử và gom chuỗi bắn lên Freshdesk.
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

        if (!$sourceEvent) {
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

    /**
     * Build the raw pipe-separated sequence string for Freshdesk
     * Example: "c:2026-07-21T00:00:00Z|g:Group L1:2026-07-21T00:00:00Z|"
     */
    public function getTimelineString(int $ticketId): string
    {
        $allHistories = TicketHistory::where('ticket_id', $ticketId)
            ->orderBy('occurred_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $entries = $allHistories->map(function ($h) {
            $formattedTime = clone $h->occurred_at;
            $formattedTime->timezone('UTC');
            $oc = $formattedTime->format('Y-m-d\TH:i:s\Z');
            $val = $h->event_value ?? '';
            
            if ($h->label) {
                return "{$h->event_key}:{$val}:{$h->label}:{$oc}";
            }
            return "{$h->event_key}:{$val}:{$oc}";
        })->toArray();

        return !empty($entries) ? implode('|', $entries) . '|' : '';
    }
}
