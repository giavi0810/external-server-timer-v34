<?php

namespace App\Services\Sla;

use App\Models\FreshdeskGroup;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Queue\FreshdeskOutboundService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RequesterRepliedHandler
{
    public function __construct(
        protected TimelineService $timelineService,
        protected FreshdeskOutboundService $outbound
    ) {
    }

    public function handle(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();
        $eventData = $event->event_data ?? [];
        $conversation = $eventData['conversation_data'] ?? [];
        $occurredAt = Carbon::parse(
            $conversation['updated_at']
                ?? $eventData['ticket_data']['updated_at']
                ?? $event->event_timestamp
        );
        $actorId = $conversation['actor_id'] ?? null;

        $this->timelineService->appendTicketEventLog(
            $ticket,
            'ct',
            'rep',
            $occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            $actorId ? (string) $actorId : null,
            $event
        );

        if (!$ticket->closed_at
            || ($ticket->reopened_at && $ticket->reopened_at->gt($ticket->closed_at))
        ) {
            return;
        }

        $minutesSinceClosed = $occurredAt->diffInMinutes($ticket->closed_at, true);
        $threshold = (int) config('freshdesk.reopen_threshold_minutes', 1440);
        $l1Id = FreshdeskGroup::active()->where('main_layer', 'L1')->value('group_id');

        if ($minutesSinceClosed <= $threshold) {
            $this->outbound->enqueueForEvent(
                $event,
                'reopen_ticket',
                'requester-reopen',
                ['group_id' => $l1Id ? (int) $l1Id : null]
            );
            Log::info('Requester reply queued an idempotent reopen operation', [
                'ticket_id' => $ticketId,
                'event_id' => $event->id,
            ]);
        } else {
            $requesterId = $eventData['ticket_data']['requester_id']
                ?? $ticket->requester_id
                ?? (($conversation['actor_type'] ?? null) === 'contact' ? $actorId : null);
            if (!$requesterId) {
                throw new \RuntimeException('Cannot create follow-up ticket without requester_id.');
            }

            $customFields = $eventData['ticket_data']['custom_fields'] ?? [];
            $processingModeKey = collect(array_keys($customFields))
                ->first(fn (string $key): bool =>
                    str_starts_with($key, 'cf_processing_mode')
                    || str_starts_with($key, 'cf_sla_mode')
                );
            $closedDate = $ticket->closed_at->format('Y-m-d');
            $createPayload = [
                'requester_id' => (int) $requesterId,
                'subject' => $eventData['ticket_data']['subject'] ?? $ticket->subject,
                'description' => "Ticket này được tạo từ phản hồi của khách hàng cho ticket "
                    ."#{$ticketId} (đã đóng vào ngày {$closedDate}).",
            ];
            if ($l1Id) {
                $createPayload['group_id'] = (int) $l1Id;
            }

            $this->outbound->enqueueForEvent(
                $event,
                'create_followup_ticket',
                'requester-followup',
                [
                    'create_payload' => $createPayload,
                    'source_ticket_id' => $ticketId,
                    'processing_mode_key' => $processingModeKey,
                    'set_due_driven' => $processingModeKey
                        && empty($customFields[$processingModeKey]),
                ]
            );
            Log::info('Requester reply queued a reconciled follow-up operation', [
                'ticket_id' => $ticketId,
                'event_id' => $event->id,
            ]);
        }

        $ticket->forceFill(['reopened_at' => $occurredAt])->save();
    }
}
