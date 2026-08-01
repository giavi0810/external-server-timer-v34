<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Models\SlaPolicy;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Services\FreshdeskApiService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * TicketCreatedHandler — Xử lý sự kiện Ticket mới được tạo.
 */
class TicketCreatedHandler
{
    protected TimerService $timerService;
    protected SlaInitializationService $initService;
    protected TimelineService $timelineService;
    protected FreshdeskApiService $freshdeskService;

    public function __construct(
        TimerService $timerService,
        SlaInitializationService $initService,
        TimelineService $timelineService,
        FreshdeskApiService $freshdeskService
    ) {
        $this->timerService = $timerService;
        $this->initService = $initService;
        $this->timelineService = $timelineService;
        $this->freshdeskService = $freshdeskService;
    }

    /**
     * Xử lý sự kiện ticket_created.
     */
    public function handle(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        Log::info("TicketCreatedHandler: xử lý ticket #{$ticketId}");

        $ticket = Ticket::where('ticket_id', $ticketData['id'] ?? $ticketId)->first();

        $updateData = [
            'subject'       => $ticketData['subject'] ?? $ticket?->subject,
            'status'        => $ticketData['status'] ?? $ticket?->status,
            'priority'      => $ticketData['priority'] ?? $ticket?->priority,
            'ticket_type'   => $ticketData['ticket_type'] ?? $ticket?->ticket_type,
            'group_id'      => $ticketData['group_id'] ?? $this->freshdeskService->resolveGroupId($ticketData['group_name'] ?? null) ?? $ticket?->group_id,
            'requester_id'  => $ticketData['requester_id'] ?? $ticket?->requester_id,
            'fd_created_at' => $ticketData['created_at'] ?? $ticket?->fd_created_at ?? now(),
        ];

        $ticket = Ticket::updateOrCreate(
            ['ticket_id' => $ticketData['id'] ?? $ticketId],
            $updateData
        );

        $ticket->priority = $this->initService->normalizePriority($ticket->priority);
        $ticket->save();

        $initialTimestamp = $ticket->fd_created_at
            ? Carbon::parse($ticket->fd_created_at)
            : ($ticketData['created_at'] ?? null
                ? Carbon::parse($ticketData['created_at'])
                : now());

        $this->initService->ensureSlaInitialized($ticket, $initialTimestamp);
        $this->createInitialStage($ticket, $event, $initialTimestamp);

        $groupLayer = $this->timerService->getGroupLayer($ticket->group_id, $ticketData['group_name'] ?? null);
        if ($groupLayer) {
            $this->timerService->startGroupTimer($ticket, $groupLayer, $initialTimestamp);
        }

        Log::info("TicketCreatedHandler Audit Log", [
            'ticket_id' => $ticketId,
            'fd_created_at' => $ticket->fd_created_at ? Carbon::parse($ticket->fd_created_at)->toIso8601String() : null,
            'initial_timestamp' => $initialTimestamp->toIso8601String(),
            'event_updated_at' => $ticketData['updated_at'] ?? null,
            'group_layer' => $groupLayer,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
        ]);

        $ticket->save();

        $createdAt = $ticket->fd_created_at
            ? Carbon::parse($ticket->fd_created_at)->format('Y-m-d\TH:i:s\Z')
            : now()->format('Y-m-d\TH:i:s\Z');

        $eventTimestamp = $event->event_timestamp;
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();

        $this->timelineService->appendTicketEventLog($ticket, 'c', $createdAt, $eventTimestamp, null, $event);

        if (!empty($ticket->ticket_type)) {
            $this->timelineService->appendTicketEventLog($ticket, 'tp', $ticket->ticket_type, $eventTimestamp, null, $event);
        }
        $groupName = $ticketData['group_name'] ?? $this->freshdeskService->resolveGroupName($ticket->group_id);
        if (!empty($groupName)) {
            $this->timelineService->appendTicketEventLog($ticket, 'g', $groupName, $eventTimestamp, null, $event);
        }

        $this->timelineService->appendTicketEventLog($ticket, 's', $this->timerService->getShortStatus($ticket->status), $eventTimestamp, null, $event);
        $this->timelineService->appendTicketEventLog($ticket, 'p', $ticket->priority, $eventTimestamp, null, $event);

        if ($ttrMetric->latest_due_date_ttr) {
            $this->timelineService->appendTicketEventLog($ticket, 'd', $ttrMetric->latest_due_date_ttr->format('Y-m-d\TH:i:s\Z'), $eventTimestamp, null, $event);
        }
        if ($rtMetric->latest_due_date_rt) {
            $this->timelineService->appendTicketEventLog($ticket, 'fr', $rtMetric->latest_due_date_rt->format('Y-m-d\TH:i:s\Z'), $eventTimestamp, null, $event);
        }

        Log::info("TicketCreatedHandler: hoàn thành ticket #{$ticketId}", [
            'ttr_total' => $ttrMetric->total_seconds,
            'rt_total'  => $rtMetric->total_seconds,
        ]);
    }

    protected function createInitialStage(Ticket $ticket, TicketEvent $event, Carbon $openedAt): void
    {
        if ($ticket->slaStages()->exists()) {
            return;
        }

        $policy = SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $ticket->priority);
        if (!$policy) {
            return;
        }

        $stage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $policy->id,
            'sequence_number' => 1,
            'priority_stage_number' => 1,
            'trigger_type' => 'initial',
            'priority' => $ticket->priority,
            'processing_mode' => 'priority-driven',
            'opened_at' => $openedAt,
            'opened_by_event_id' => $event->id,
        ]);

        $ttr = $ticket->getOrCreateTtrMetric();
        $rt = $ticket->getOrCreateFirstResponseMetric();
        foreach ([
            ['type' => 'ttr', 'goal' => $policy->total_seconds, 'used' => $ttr->used_seconds, 'due' => $ttr->latest_due_date_ttr],
            ['type' => 'rt', 'goal' => $policy->rt_seconds, 'used' => $rt->used_seconds, 'due' => $rt->latest_due_date_rt],
        ] as $metric) {
            TicketSlaStageMetric::create([
                'ticket_sla_stage_id' => $stage->id,
                'metric_type' => $metric['type'],
                'sla_goal_seconds' => $metric['goal'],
                'used_before_seconds' => $metric['used'],
                'effective_sla_seconds' => $metric['goal'],
                'standard_due_at' => $metric['due'],
                'adjusted_due_at' => $metric['due'],
            ]);
        }
    }
}
