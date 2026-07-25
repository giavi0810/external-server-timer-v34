<?php

namespace App\Services;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\Sla\TicketCreatedHandler;
use App\Services\Sla\StatusChangedHandler;
use App\Services\Sla\PriorityChangedHandler;
use App\Services\Sla\GroupChangedHandler;
use App\Services\Sla\AgentRepliedHandler;
use App\Services\Sla\RequesterRepliedHandler;
use App\Services\Sla\DueDateChangedHandler;
use App\Services\Sla\TicketReopenedHandler;
use App\Services\Sla\TimerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SLA Calculation Service — Orchestrator.
 */
class SlaCalculationService
{
    public function handleTicketCreated(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        Log::debug("SlaCalculationService: ticket_created changes", ['ticket_id' => $ticketId, 'changes' => $event->field_changes]);
        app(TicketCreatedHandler::class)->handle($ticketId, $ticketData, $event);
    }

    public function handleStatusChanged(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        Log::debug("SlaCalculationService: status_changed changes", ['ticket_id' => $ticketId, 'changes' => $changes]);
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);
        $this->detectReplyActivity($ticketId, $ticketData, $event);

        app(StatusChangedHandler::class)->handle($ticketId, $ticketData, $changes, $event);

        $this->processSecondaryChanges($ticketId, $ticketData, $changes, $event, 'status');
    }

    public function handlePriorityChanged(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        Log::debug("SlaCalculationService: priority_changed changes", ['ticket_id' => $ticketId, 'changes' => $changes]);
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);
        $this->detectReplyActivity($ticketId, $ticketData, $event);

        if (class_exists(PriorityChangedHandler::class)) {
            app(PriorityChangedHandler::class)->handle($ticketId, $ticketData, $changes, $event);
        }

        $this->processSecondaryChanges($ticketId, $ticketData, $changes, $event, 'priority');
    }

    public function handleGroupChanged(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        Log::debug("SlaCalculationService: group_changed changes", ['ticket_id' => $ticketId, 'changes' => $changes]);
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);
        $this->detectReplyActivity($ticketId, $ticketData, $event);

        if (class_exists(GroupChangedHandler::class)) {
            app(GroupChangedHandler::class)->handle($ticketId, $ticketData, $changes, $event);
        }

        $this->processSecondaryChanges($ticketId, $ticketData, $changes, $event, 'group');
    }

    protected function processSecondaryChanges(int $ticketId, array $ticketData, array $changes, TicketEvent $event, string $primaryField): void
    {
        foreach ($changes as $change) {
            $field = $change['field'] ?? '';

            if ($field === 'status' && $primaryField !== 'status') {
                Log::info("SlaCalculationService: Phát hiện Status thay đổi phụ", ['ticket_id' => $ticketId]);
                app(StatusChangedHandler::class)->handle($ticketId, $ticketData, [$change], $event);
            }

            if ($field === 'priority' && $primaryField !== 'priority') {
                Log::info("SlaCalculationService: Phát hiện Priority thay đổi phụ", ['ticket_id' => $ticketId]);
                if (class_exists(PriorityChangedHandler::class)) {
                    app(PriorityChangedHandler::class)->handle($ticketId, $ticketData, [$change], $event);
                }
            }

            if (in_array($field, ['group_id', 'group_name'], true) && $primaryField !== 'group') {
                Log::info("SlaCalculationService: Phát hiện Group thay đổi phụ", ['ticket_id' => $ticketId]);
                if (class_exists(GroupChangedHandler::class)) {
                    app(GroupChangedHandler::class)->handle($ticketId, $ticketData, [$change], $event);
                }
            }

            if ($field === 'due_by' && $primaryField !== 'due_date' && $primaryField !== 'priority') {
                $statusChange = collect($changes)->firstWhere('field', 'status');
                $oldStatus = $statusChange['old_value'] ?? null;
                $newStatus = $statusChange['new_value'] ?? $ticketData['status'] ?? '';

                $ticket = Ticket::where('ticket_id', $ticketId)->first();
                $isDueDriven = $ticket && $ticket->getOrCreateTtrMetric()->sla_mode === 'due-driven';

                if ($primaryField === 'status' && !$isDueDriven) {
                    Log::info("SlaCalculationService: Bỏ qua Due Date thay đổi phụ trong status_changed", [
                        'ticket_id' => $ticketId,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ]);
                    continue;
                }

                $timerService = app(TimerService::class);
                if (
                    is_string($oldStatus) &&
                    ($timerService->isEndStatus($oldStatus) || $timerService->isPauseStatus($oldStatus)) &&
                    $timerService->isRunStatus($newStatus) &&
                    !$isDueDriven
                ) {
                    Log::info("SlaCalculationService: Bỏ qua Due Date thay đổi phụ vì Ticket đang được Reopen/Unpause", ['ticket_id' => $ticketId]);
                    continue;
                }

                if ($isDueDriven) {
                    Log::info("SlaCalculationService: Phát hiện thay đổi Due Date từ App (due-driven)", ['ticket_id' => $ticketId]);
                } else {
                    Log::info("SlaCalculationService: Phát hiện Due Date thay đổi phụ", ['ticket_id' => $ticketId]);
                }

                if (class_exists(DueDateChangedHandler::class)) {
                    app(DueDateChangedHandler::class)->handle($ticketId, $ticketData, [$change], $event);
                }
            }
        }
    }

    public function handleAgentReplied(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);
        
        $ticket = Ticket::where('ticket_id', $ticketId)->first();
        if ($ticket) {
            $metric = $ticket->getOrCreateFirstResponseMetric();
            if (isset($ticketData['agent_reply_count'])) {
                $metric->agent_reply_count = $ticketData['agent_reply_count'];
            }
            if (isset($ticketData['agent_responded_at'])) {
                $metric->agent_responded_at = Carbon::parse($ticketData['agent_responded_at']);
            }
            $metric->save();
        }

        app(AgentRepliedHandler::class)->handle($ticketId, $ticketData, $event);
    }

    public function handleRequesterReplied(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);

        $ticket = Ticket::where('ticket_id', $ticketId)->first();
        if ($ticket) {
            $metric = $ticket->getOrCreateFirstResponseMetric();
            if (isset($ticketData['customer_reply_count'])) {
                $metric->requester_reply_count = $ticketData['customer_reply_count'];
            }
            if (isset($ticketData['requester_responded_at'])) {
                $metric->requester_responded_at = Carbon::parse($ticketData['requester_responded_at']);
            }
            $metric->save();
        }

        app(RequesterRepliedHandler::class)->handle($ticketId, $ticketData, $event);
    }

    protected function detectReplyActivity(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        if ($event->event_type === TicketEvent::EVENT_AGENT_REPLIED || $event->event_type === TicketEvent::EVENT_REQUESTER_REPLIED) {
            return;
        }

        $ticket = Ticket::where('ticket_id', $ticketId)->first();
        if (!$ticket) return;

        $metric = $ticket->getOrCreateFirstResponseMetric();
        
        $newAgentCount = $ticketData['agent_reply_count'] ?? null;
        $newCustCount  = $ticketData['customer_reply_count'] ?? null;

        if ($newAgentCount !== null && $newAgentCount > (int) $metric->agent_reply_count) {
            Log::info("SlaCalculationService: Tự động phát hiện Agent Reply qua Count", [
                'ticket_id' => $ticketId,
                'old_count' => $metric->agent_reply_count,
                'new_count' => $newAgentCount
            ]);
            $metric->agent_reply_count = $newAgentCount;
            if (isset($ticketData['agent_responded_at'])) {
                $metric->agent_responded_at = Carbon::parse($ticketData['agent_responded_at']);
            }
            $metric->save();

            app(AgentRepliedHandler::class)->handle($ticketId, $ticketData, $event);
        }

        if ($newCustCount !== null && $newCustCount > (int) $metric->requester_reply_count) {
            Log::info("SlaCalculationService: Tự động phát hiện Customer Reply qua Count", [
                'ticket_id' => $ticketId,
                'old_count' => $metric->requester_reply_count,
                'new_count' => $newCustCount
            ]);
            $metric->requester_reply_count = $newCustCount;
            if (isset($ticketData['requester_responded_at'])) {
                $metric->requester_responded_at = Carbon::parse($ticketData['requester_responded_at']);
            }
            $metric->save();

            app(RequesterRepliedHandler::class)->handle($ticketId, $ticketData, $event);
        }
    }

    public function handleDueDateChanged(int $ticketId, array $ticketData, array $changes, TicketEvent $event): void
    {
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);
        if (class_exists(DueDateChangedHandler::class)) {
            app(DueDateChangedHandler::class)->handle($ticketId, $ticketData, $changes, $event);
        }
    }

    public function handleTicketReopened(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $this->syncRunningTimersToTicketEventTime($ticketId, $event);
        if (class_exists(TicketReopenedHandler::class)) {
            app(TicketReopenedHandler::class)->handle($ticketId, $ticketData, $event);
        }
    }

    protected function syncRunningTimersToTicketEventTime(int $ticketId, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->first();
        if (!$ticket) {
            return;
        }

        $eventAt = $this->resolveTicketEventTimestamp($event);
        $timerService = app(TimerService::class);

        $this->bootstrapMissingRunningGroupTimer($ticket, $eventAt, $timerService, $event);

        $hasActiveGroupTimer = $ticket->groupMetrics()->whereNotNull('started_at')->exists();
        if ($hasActiveGroupTimer) {
            $timerService->accumulateGroupUsedTime($ticket, $eventAt);
        }

        if ($event->event_type === TicketEvent::EVENT_PRIORITY_CHANGED) {
            $statusMetric = $ticket->getOrCreateStatusMetric();
            $timerService->recalculateTtrMetrics($ticket, $statusMetric, $eventAt);
        }

        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        if (
            $rtMetric->started_at &&
            !$rtMetric->hasFirstResponse() &&
            $rtMetric->status === 'running'
        ) {
            $startedAt = Carbon::parse($rtMetric->started_at);
            if ($eventAt->greaterThan($startedAt)) {
                $elapsed = $startedAt->diffInSeconds($eventAt);
                $rtMetric->used_seconds += max(0, $elapsed);
                $timerService->recalculateRtMetrics($rtMetric);
                $rtMetric->started_at = $eventAt;
                $rtMetric->save();
            }
        }

        $ticket->save();

        Log::debug("SlaCalculationService: synced running timers to event time", [
            'ticket_id' => $ticketId,
            'event_at' => $eventAt->toIso8601String(),
        ]);
    }

    protected function bootstrapMissingRunningGroupTimer(Ticket $ticket, Carbon $eventAt, TimerService $timerService, TicketEvent $event): void
    {
        if (!$this->wasTicketRunningBeforeTicketEvent($timerService, $ticket, $event, $eventAt)) {
            return;
        }

        $hasActiveGroupTimer = $ticket->groupMetrics()->whereNotNull('started_at')->exists();
        if ($hasActiveGroupTimer) {
            return;
        }

        $groupLayer = $timerService->getGroupLayer($ticket->group_id);
        if (!$groupLayer) {
            return;
        }

        $groupId = $ticket->group_id;

        $startedAt = $this->resolveCurrentRunStartedAt($ticket, $eventAt, $timerService);
        if ($startedAt->greaterThan($eventAt)) {
            $startedAt = $eventAt->copy();
        }

        $aggregateTimer = $ticket->getOrCreateGroupMetric($groupLayer, null);
        $changed = false;

        if ($groupId) {
            $specificTimer = $ticket->getOrCreateGroupMetric($groupLayer, $groupId);
            if (!$specificTimer->started_at) {
                $specificTimer->started_at = $startedAt;
                $specificTimer->save();
                $changed = true;
            }
        }

        if (!$aggregateTimer->started_at) {
            $aggregateTimer->started_at = $startedAt;
            $aggregateTimer->save();
            $changed = true;
        }

        if ($changed) {
            Log::info('SlaCalculationService: bootstrapped missing running group timer', [
                'ticket_id' => $ticket->ticket_id,
                'group_id' => $ticket->group_id,
                'group_name' => $timerService->resolveGroupName($ticket->group_id),
                'group_layer' => $groupLayer,
                'started_at' => $startedAt->toIso8601String(),
            ]);
        }
    }

    protected function resolveCurrentRunStartedAt(Ticket $ticket, Carbon $eventAt, TimerService $timerService): Carbon
    {
        $candidate = null;
        $assignCandidate = static function (Carbon $value) use (&$candidate, $eventAt): void {
            if ($value->greaterThan($eventAt)) {
                return;
            }

            if ($candidate === null || $value->greaterThan($candidate)) {
                $candidate = $value;
            }
        };

        $rtMetric = $ticket->firstResponseMetric ?: $ticket->getOrCreateFirstResponseMetric();
        if ($rtMetric->status === 'running' && $rtMetric->started_at) {
            $assignCandidate(Carbon::parse($rtMetric->started_at));
        }

        if ($ticket->fd_created_at) {
            $assignCandidate(Carbon::parse($ticket->fd_created_at));
        }

        return $candidate ? $candidate->copy() : $eventAt->copy();
    }

    protected function wasTicketRunningBeforeTicketEvent(
        TimerService $timerService,
        Ticket $ticket,
        TicketEvent $event,
        Carbon $eventAt
    ): bool {
        if ($event->event_type === TicketEvent::EVENT_STATUS_CHANGED) {
            $statusChange = collect($event->field_changes ?? [])->firstWhere('field', 'status');
            $oldStatus = $statusChange['old_value'] ?? null;
            if (is_string($oldStatus) && $oldStatus !== '') {
                return $timerService->isRunStatus($oldStatus);
            }
        }

        $statusMetric = $ticket->statusMetric ?: $ticket->getOrCreateStatusMetric();
        if ($statusMetric->waiting_started_at || $statusMetric->pending_started_at) {
            return false;
        }

        return $timerService->isRunStatus((string) $ticket->status);
    }

    protected function resolveTicketEventTimestamp(TicketEvent $event): Carbon
    {
        if ($event->event_timestamp) {
            return Carbon::parse($event->event_timestamp);
        }

        $updatedAtRaw = $event->event_data['ticket_data']['updated_at'] ?? null;

        if (!$updatedAtRaw && isset($event->event_data['conversation_data']['updated_at'])) {
            $updatedAtRaw = $event->event_data['conversation_data']['updated_at'];
        }

        if ($updatedAtRaw) {
            return Carbon::parse($updatedAtRaw);
        }

        return now();
    }
}
