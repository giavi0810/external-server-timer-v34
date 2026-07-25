<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * AgentRepliedHandler — Xử lý sự kiện Agent phản hồi lần đầu.
 */
class AgentRepliedHandler
{
    protected TimerService $timerService;
    protected TimelineService $timelineService;

    public function __construct(TimerService $timerService, TimelineService $timelineService)
    {
        $this->timerService = $timerService;
        $this->timelineService = $timelineService;
    }

    /**
     * Xử lý sự kiện agent_replied.
     */
    public function handle(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();

        $now = now();

        $eventData = $event->event_data ?? [];
        $convData = $eventData['conversation_data'] ?? [];
        
        $updatedAtRaw = $convData['updated_at'] 
                        ?? $eventData['ticket_data']['updated_at'] 
                        ?? null;
        
        $actorId = $convData['actor_id'] ?? null;
        $actorLabel = $actorId ? (string)$actorId : null;

        if ($updatedAtRaw) {
            $now = \Carbon\Carbon::parse($updatedAtRaw);
        }

        $actorType = $convData['actor_type'] ?? null;
        if ($actorType && !in_array($actorType, ['agent'])) {
            Log::info("AgentRepliedHandler: Bỏ qua chốt RT vì actor_type không phải agent", [
                'ticket_id' => $ticketId,
                'actor_type' => $actorType,
            ]);
            return;
        }

        if (!$rtMetric->hasFirstResponse()) {
            $rtMetric->first_response_at = $now;

            $this->timerService->finalizeRtUsedTime($rtMetric, $now);

            $rtMetric->status = 'ended_replied';
            $this->timerService->recalculateRtMetrics($rtMetric);

            $rtMetric->save();

            $this->timelineService->appendTicketEventLog($ticket, 'fr', $now->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp);
            
            Log::info("AgentRepliedHandler: RT ended by Agent replied", [
                'ticket_id' => $ticketId,
                'rt_used' => $rtMetric->used_seconds,
            ]);
        }
        
        if ($actorLabel) {
            $this->timelineService->appendTicketEventLog($ticket, 'a', 'rep', $now->format('Y-m-d\TH:i:s\Z'), $actorLabel);
        } else {
            $this->timelineService->appendTicketEventLog($ticket, 'a', 'rep', $now->format('Y-m-d\TH:i:s\Z'));
        }
    }
}
