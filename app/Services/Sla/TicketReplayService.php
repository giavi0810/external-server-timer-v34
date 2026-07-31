<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\TicketDueDateChange;
use App\Models\TicketEvent;
use App\Models\TicketFirstResponseMetric;
use App\Models\TicketGroupMetric;
use App\Models\TicketGroupSession;
use App\Models\TicketHistory;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Models\TicketStatusMetric;
use App\Models\TicketTtrMetric;
use Illuminate\Support\Facades\DB;
use LogicException;

class TicketReplayService
{
    /**
     * Prepare ticket for a complete SLA replay from baseline event (EVENT_TICKET_CREATED).
     */
    public function prepare(int $ticketId): void
    {
        DB::transaction(function () use ($ticketId): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [$ticketId]);
            }
            $ticket = Ticket::query()->where('ticket_id', $ticketId)->lockForUpdate()->firstOrFail();
            $creationEvent = TicketEvent::query()
                ->where('ticket_id', $ticketId)
                ->where('event_type', TicketEvent::EVENT_TICKET_CREATED)
                ->orderBy('event_timestamp')
                ->orderBy('id')
                ->first();

            if (!$creationEvent) {
                throw new LogicException("Ticket {$ticketId} không có ticket_created baseline để replay.");
            }

            $stageIds = TicketSlaStage::where('ticket_id', $ticketId)->pluck('id');
            if ($stageIds->isNotEmpty()) {
                TicketSlaStageMetric::whereIn('ticket_sla_stage_id', $stageIds)->delete();
            }

            TicketDueDateChange::where('ticket_id', $ticketId)->delete();
            TicketSlaStage::where('ticket_id', $ticketId)->delete();
            TicketGroupSession::where('ticket_id', $ticketId)->delete();
            TicketGroupMetric::where('ticket_id', $ticketId)->delete();
            TicketHistory::where('ticket_id', $ticketId)->delete();
            TicketFirstResponseMetric::where('ticket_id', $ticketId)->delete();
            TicketStatusMetric::where('ticket_id', $ticketId)->delete();
            TicketTtrMetric::where('ticket_id', $ticketId)->delete();

            $data = $creationEvent->getTicketData();
            $ticket->fill([
                'subject' => $data['subject'] ?? $ticket->subject,
                'status' => $data['status'] ?? $ticket->status,
                'priority' => $data['priority'] ?? $ticket->priority,
                'ticket_type' => $data['ticket_type'] ?? $ticket->ticket_type,
                'group_id' => $data['group_id'] ?? $ticket->group_id,
                'requester_id' => $data['requester_id'] ?? $ticket->requester_id,
                'fd_created_at' => $data['created_at'] ?? $ticket->fd_created_at ?? $creationEvent->event_timestamp,
                'resolved_at' => null,
                'closed_at' => null,
                'reopened_at' => null,
            ])->save();

            TicketEvent::query()->where('ticket_id', $ticketId)->update([
                'status' => TicketEvent::STATUS_PENDING,
                'attempt_count' => 0,
                'last_error' => null,
                'locked_at' => null,
                'processed_at' => null,
                'processing_token' => null,
            ]);
        });
    }
}
