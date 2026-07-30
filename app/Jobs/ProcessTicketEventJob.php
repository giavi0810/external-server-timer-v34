<?php

namespace App\Jobs;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\Sla\AppTimerSyncService;
use App\Services\Sla\TicketReplayService;
use App\Services\SlaCalculationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTicketEventJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $timeout = 105;
    public int $tries = 4;
    public array $backoff = [15, 30, 60];
    public int $uniqueFor = 120;

    protected int $ticketId;
    protected bool $isRecoveryDispatch;
    protected bool $replayAll;

    public function __construct(int $ticketId, bool $isRecoveryDispatch = false, bool $replayAll = false)
    {
        $this->ticketId = $ticketId;
        $this->isRecoveryDispatch = $isRecoveryDispatch;
        $this->replayAll = $replayAll;
    }

    public function uniqueId(): string
    {
        $prefix = $this->replayAll
            ? 'ticket_replay_'
            : ($this->isRecoveryDispatch ? 'ticket_recovery_' : 'ticket_');

        return $prefix . $this->ticketId;
    }

    public function handle(SlaCalculationService $slaService, ?TicketReplayService $replayService = null): void
    {
        $startTime = microtime(true);
        $lock = Cache::lock("ticket_processing:{$this->ticketId}", $this->timeout + 15);

        if (!$lock->get()) {
            Log::info('Ticket is already being processed, dispatching delayed retry job', [
                'ticket_id' => $this->ticketId,
            ]);
            self::dispatch($this->ticketId, $this->isRecoveryDispatch, $this->replayAll)
                ->delay(now()->addSeconds(5));
            return;
        }

        try {
            Log::info('Ticket job started', array_merge([
                'ticket_id' => $this->ticketId,
            ], $this->jobContext()));

            if ($this->replayAll) {
                ($replayService ?? app(TicketReplayService::class))->prepare($this->ticketId);
            }

            $hasFailedTicketEvent = !$this->replayAll && TicketEvent::query()
                ->where('ticket_id', $this->ticketId)
                ->where('status', TicketEvent::STATUS_FAILED)
                ->exists();

            if ($hasFailedTicketEvent) {
                Log::warning('Ticket has failed events requiring manual handling, auto-processing skipped', [
                    'ticket_id' => $this->ticketId,
                ]);
                return;
            }

            $ticket = Ticket::query()->where('ticket_id', $this->ticketId)->first();
            if (!$ticket) {
                Log::warning('Ticket not found, skipping ticket batch', [
                    'ticket_id' => $this->ticketId,
                ]);
                return;
            }

            $eventsCount = 0;
            $shouldSyncTicket = false;

            while (true) {
                $events = $this->getPendingTicketEvents();

                if ($events->isNotEmpty()) {
                    foreach ($events as $event) {
                        try {
                            $event->markAsProcessing();
                            $shouldSyncTicket = $this->processSingleTicketEvent($event, $slaService) || $shouldSyncTicket;
                            $eventsCount++;
                        } catch (\Throwable $exception) {
                            $event->markAsPending();

                            Log::warning('TicketEvent processing failed, queue retry scheduled', [
                                'event_id'      => $event->id,
                                'ticket_id'     => $event->ticket_id,
                                'event_type'    => $event->event_type,
                                'error_reason'  => $exception->getMessage(),
                                'exception_class' => $exception::class,
                                'error_code' => $exception->getCode(),
                                'attempt' => $this->attempts(),
                                'max_tries' => $this->tries,
                                'next_retry_in_seconds' => $this->nextRetryDelaySeconds(),
                                'phase' => 'event_processing',
                            ]);

                            throw $exception;
                        }
                    }

                    continue;
                }

                $ticket->refresh();
                $hasCompletedNotSynced = $this->hasProcessedNotSyncedTicketEvents($ticket);

                if ($shouldSyncTicket || $hasCompletedNotSynced) {
                    if (!$this->syncTicketBatch($ticket)) {
                        return;
                    }
                    $shouldSyncTicket = false;

                    if ($this->hasPendingTicketEvents()) {
                        Log::info('New ticket events detected after sync, continuing batch', [
                            'ticket_id' => $this->ticketId,
                            'events_count' => $eventsCount,
                        ]);
                        continue;
                    }
                }

                if ($eventsCount === 0) {
                    Log::debug('No pending events and nothing to sync for ticket', [
                        'ticket_id' => $this->ticketId,
                    ]);
                }

                break;
            }

            $duration = (int) ((microtime(true) - $startTime) * 1000);
            Log::info('Ticket event batch processed', [
                'ticket_id'    => $this->ticketId,
                'events_count' => $eventsCount,
                'duration_ms'  => $duration,
            ]);
        } finally {
            $lock->release();
        }
    }

    protected function processSingleTicketEvent(TicketEvent $event, SlaCalculationService $slaService): bool
    {
        $shouldSyncTicket = false;

        DB::transaction(function () use ($event, $slaService, &$shouldSyncTicket) {
            $ticketData = $event->getTicketData();
            $changes = $event->getFieldChanges();

            Log::info('Processing event', [
                'event_id'   => $event->id,
                'event_type' => $event->event_type,
                'ticket_id'  => $event->ticket_id,
            ]);

            switch ($event->event_type) {
                case TicketEvent::EVENT_TICKET_CREATED:
                    $slaService->handleTicketCreated($event->ticket_id, $ticketData, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_STATUS_CHANGED:
                    $slaService->handleStatusChanged($event->ticket_id, $ticketData, $changes, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_PRIORITY_CHANGED:
                    $slaService->handlePriorityChanged($event->ticket_id, $ticketData, $changes, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_GROUP_CHANGED:
                    $slaService->handleGroupChanged($event->ticket_id, $ticketData, $changes, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_AGENT_REPLIED:
                    $slaService->handleAgentReplied($event->ticket_id, $ticketData, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_REQUESTER_REPLIED:
                    $slaService->handleRequesterReplied($event->ticket_id, $ticketData, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_DUE_DATE_CHANGED:
                    $slaService->handleDueDateChanged($event->ticket_id, $ticketData, $changes, $event);
                    $shouldSyncTicket = true;
                    break;

                case TicketEvent::EVENT_TICKET_REOPENED:
                    $slaService->handleTicketReopened($event->ticket_id, $ticketData, $event);
                    $shouldSyncTicket = true;
                    break;

                default:
                    Log::warning('Unknown event type', [
                        'event_type' => $event->event_type,
                        'event_id'   => $event->id,
                    ]);
                    break;
            }

            $event->markAsProcessed();
        });

        return $shouldSyncTicket;
    }

    public function failed(\Throwable $exception): void
    {
        $affectedTicketEvents = TicketEvent::query()
            ->where('ticket_id', $this->ticketId)
            ->whereIn('status', [
                TicketEvent::STATUS_PENDING,
                TicketEvent::STATUS_QUEUED,
                TicketEvent::STATUS_PROCESSING,
            ])
            ->update([
                'status' => TicketEvent::STATUS_FAILED,
            ]);

        Log::error('ProcessTicketEventJob failed at job level', [
            'ticket_id' => $this->ticketId,
            'error' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'error_code' => $exception->getCode(),
            'phase' => 'job_failed',
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'failed_events_marked' => $affectedTicketEvents,
            'manual_required' => true,
        ]);

        if (class_exists(\App\Services\RocketChatService::class)) {
            try {
                app(\App\Services\RocketChatService::class)->sendSystemErrorAlert($exception, $this->ticketId);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch RocketChat alert on job failure', ['error' => $e->getMessage()]);
            }
        }
    }

    protected function getPendingTicketEvents()
    {
        return TicketEvent::query()
            ->where('ticket_id', $this->ticketId)
            ->whereIn('status', [TicketEvent::STATUS_PENDING, TicketEvent::STATUS_QUEUED])
            ->orderBy('event_timestamp')
            ->orderBy('id')
            ->get();
    }

    protected function hasPendingTicketEvents(): bool
    {
        return TicketEvent::query()
            ->where('ticket_id', $this->ticketId)
            ->whereIn('status', [TicketEvent::STATUS_PENDING, TicketEvent::STATUS_QUEUED])
            ->exists();
    }

    protected function hasProcessedNotSyncedTicketEvents(Ticket $ticket): bool
    {
        return TicketEvent::query()
            ->where('ticket_id', $this->ticketId)
            ->where('status', TicketEvent::STATUS_PROCESSED)
            ->whereNotNull('processed_at')
            ->when(
                $ticket->updated_at,
                fn ($query) => $query->where('processed_at', '>', $ticket->updated_at)
            )
            ->exists();
    }

    protected function syncTicketBatch(Ticket $ticket): bool
    {
        try {
            if (class_exists(AppTimerSyncService::class)) {
                app(AppTimerSyncService::class)->syncTicket($ticket);
            }
            $ticket->touch();
            usleep(500000); // 0.5s
            return true;
        } catch (\Throwable $exception) {
            $errorMessage = $exception->getMessage();
            $delay = $this->nextRetryDelaySeconds();

            if (str_contains($errorMessage, 'rate_limited') || str_contains($errorMessage, 'status=429')) {
                $delay = 60;
                if (preg_match('/retry_after=(\d+)/', $errorMessage, $matches)) {
                    $delay = (int) $matches[1];
                }
                if ($delay < 1) {
                    $delay = 60;
                }
            }

            Log::error('Ticket sync failed, queue retry scheduled', [
                'ticket_id' => $this->ticketId,
                'error_reason' => $errorMessage,
                'exception_class' => $exception::class,
                'error_code' => $exception->getCode(),
                'next_retry_in_seconds' => $delay,
                'phase' => 'sync_to_freshdesk',
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);

            if (str_contains($errorMessage, 'rate_limited') || str_contains($errorMessage, 'status=429')) {
                $this->release($delay);
                return false;
            }

            throw $exception;
        }
    }

    protected function nextRetryDelaySeconds(): ?int
    {
        $attempt = $this->attempts();
        if ($attempt >= $this->tries) {
            return null;
        }

        $index = max(0, min(count($this->backoff) - 1, $attempt - 1));
        return $this->backoff[$index] ?? null;
    }

    protected function jobContext(): array
    {
        return [
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'job_uuid' => $this->job?->uuid(),
            'queue' => $this->job?->getQueue(),
            'connection' => $this->job?->getConnectionName(),
            'dispatch_kind' => $this->isRecoveryDispatch ? 'recovery' : 'normal',
            'replay_all' => $this->replayAll,
        ];
    }
}
