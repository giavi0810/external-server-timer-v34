<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketEvent;

class RequesterRepliedHandler
{
    public function __construct(
        protected TimelineService $timelineService
    ) {
    }

    public function handle(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();
        $eventData = $event->event_data ?? [];
        $conversation = $eventData['conversation_data'] ?? [];
        $occurredAt = $event->occurredAt();
        $actorId = $conversation['actor_id'] ?? null;

        $this->timelineService->appendTicketEventLog(
            $ticket,
            'ct',
            'rep',
            $occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            $actorId ? (string) $actorId : null,
            $event
        );

        // Reopen/follow-up lifecycle is intentionally excluded from Release 1.
        // Requester replies are recorded in the timeline only.
    }
}
