<?php

namespace App\Jobs;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Models\TicketLogicOutbox;
use App\Models\FreshdeskOutboundOperation;
use App\Services\Sla\TicketReplayService;
use App\Services\SlaCalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessTicketEventJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 105;
    public int $tries = 4;
    public array $backoff = [15, 30, 60];
    protected int $ticketId;
    protected bool $isRecoveryDispatch;
    protected bool $replayAll;
    protected ?string $outboxToken;
    protected ?int $targetGeneration;
    protected ?int $syncEpoch;

    public function __construct(
        int $ticketId,
        bool $isRecoveryDispatch = false,
        bool $replayAll = false,
        ?string $outboxToken = null,
        ?int $targetGeneration = null,
        ?int $syncEpoch = null
    )
    {
        $this->ticketId = $ticketId;
        $this->isRecoveryDispatch = $isRecoveryDispatch;
        $this->replayAll = $replayAll;
        $this->outboxToken = $outboxToken;
        $this->targetGeneration = $targetGeneration;
        $this->syncEpoch = $syncEpoch;
    }

    public function handle(SlaCalculationService $slaService, ?TicketReplayService $replayService = null): void
    {
        $startTime = microtime(true);

        if ($this->outboxToken !== null && !$this->claimLogicOutbox()) {
            Log::notice('Stale ticket logic delivery ignored', $this->jobContext());
            return;
        }

        $lock = Cache::lock("ticket_processing:{$this->ticketId}", $this->timeout + 15);

        if (!$lock->get()) {
            Log::info('Ticket is already being processed, dispatching delayed retry job', [
                'ticket_id' => $this->ticketId,
            ]);
            if ($this->outboxToken !== null) {
                TicketLogicOutbox::query()
                    ->where('ticket_id', $this->ticketId)
                    ->where('lease_token', $this->outboxToken)
                    ->where('requested_generation', '>=', $this->targetGeneration)
                    ->update([
                        'state' => $this->replayAll ? 'replaying' : 'dispatched',
                        'visibility_at' => now()->addSeconds(10),
                    ]);
                $this->release(5);
            }
            return;
        }

        try {
            Log::info('Ticket job started', array_merge([
                'ticket_id' => $this->ticketId,
            ], $this->jobContext()));

            if ($this->replayAll && $this->outboxToken === null) {
                ($replayService ?? app(TicketReplayService::class))->prepare($this->ticketId);
            }

            $hasFailedTicketEvent = !$this->replayAll && TicketEvent::query()
                ->where('ticket_id', $this->ticketId)
                ->where('status', TicketEvent::STATUS_FAILED)
                ->exists();

            if ($hasFailedTicketEvent) {
                $this->blockLogicOutbox('Ticket has failed events requiring manual handling.');
                Log::warning('Ticket has failed events requiring manual handling, auto-processing skipped', [
                    'ticket_id' => $this->ticketId,
                ]);
                return;
            }

            $ticket = Ticket::query()->where('ticket_id', $this->ticketId)->first();
            if (!$ticket) {
                $this->blockLogicOutbox('Ticket record is missing.');
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
                            $shouldSyncTicket = $this->processSingleTicketEvent($event, $slaService) || $shouldSyncTicket;
                            $eventsCount++;

                            if ($this->replayAll
                                && ($eventsCount >= 50 || microtime(true) - $startTime >= 70)
                            ) {
                                $this->dispatchReplayContinuation();
                                return;
                            }
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

                if ($shouldSyncTicket || $hasCompletedNotSynced || $this->outboxToken !== null) {
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
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [$this->ticketId]);
            }
            $event = TicketEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (!in_array($event->status, [TicketEvent::STATUS_PENDING, TicketEvent::STATUS_QUEUED], true)) {
                return;
            }
            $event->forceFill([
                'status' => TicketEvent::STATUS_PROCESSING,
                'locked_at' => now(),
                'processing_token' => (string) Str::uuid(),
                'attempt_count' => $event->attempt_count + 1,
            ])->save();

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
            ->when(
                $this->targetGeneration !== null,
                fn ($query) => $query->where('logic_generation', '<=', $this->targetGeneration)
            )
            ->update([
                'status' => TicketEvent::STATUS_FAILED,
                'locked_at' => null,
                'processing_token' => null,
            ]);

        if ($this->outboxToken !== null) {
            TicketLogicOutbox::query()
                ->where('ticket_id', $this->ticketId)
                ->where('lease_token', $this->outboxToken)
                ->where('sync_epoch', $this->syncEpoch)
                ->update([
                    'state' => 'blocked',
                    'visibility_at' => null,
                    'last_error' => $exception->getMessage(),
                ]);
        }

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
            ->when(
                $this->targetGeneration !== null,
                fn ($query) => $query->where('logic_generation', '<=', $this->targetGeneration)
            )
            ->orderBy('event_timestamp')
            ->orderBy('source_order_key')
            ->orderBy('id')
            ->get();
    }

    protected function hasPendingTicketEvents(): bool
    {
        return TicketEvent::query()
            ->where('ticket_id', $this->ticketId)
            ->whereIn('status', [TicketEvent::STATUS_PENDING, TicketEvent::STATUS_QUEUED])
            ->when(
                $this->targetGeneration !== null,
                fn ($query) => $query->where('logic_generation', '<=', $this->targetGeneration)
            )
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
        if ($this->outboxToken !== null) {
            return $this->acknowledgeAndQueueOutbound($ticket);
        }

        try {
            $ticket->touch();
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
            'target_generation' => $this->targetGeneration,
            'sync_epoch' => $this->syncEpoch,
        ];
    }

    protected function claimLogicOutbox(): bool
    {
        $states = $this->replayAll ? ['replaying', 'replay_continue_dispatched'] : ['dispatched'];
        $nextState = $this->replayAll ? 'replaying' : 'processing';

        return TicketLogicOutbox::query()
            ->where('ticket_id', $this->ticketId)
            ->whereIn('state', $states)
            ->where('lease_token', $this->outboxToken)
            ->where('requested_generation', '>=', $this->targetGeneration)
            ->where('sync_epoch', $this->syncEpoch)
            ->update([
                'state' => $nextState,
                'visibility_at' => now()->addSeconds(150),
                'heartbeat_at' => now(),
            ]) === 1;
    }

    protected function acknowledgeAndQueueOutbound(Ticket $ticket): bool
    {
        return DB::transaction(function () use ($ticket): bool {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [$this->ticketId]);
            }
            $outbox = TicketLogicOutbox::query()
                ->where('ticket_id', $this->ticketId)
                ->lockForUpdate()
                ->first();

            if (!$outbox
                || !hash_equals((string) $outbox->lease_token, (string) $this->outboxToken)
                || $outbox->sync_epoch !== $this->syncEpoch
            ) {
                return false;
            }

            $blocked = TicketEvent::query()
                ->where('ticket_id', $this->ticketId)
                ->where('logic_generation', '<=', $this->targetGeneration)
                ->whereIn('status', [
                    TicketEvent::STATUS_PENDING,
                    TicketEvent::STATUS_QUEUED,
                    TicketEvent::STATUS_PROCESSING,
                    TicketEvent::STATUS_FAILED,
                ])
                ->exists();

            if ($blocked) {
                $failed = TicketEvent::query()
                    ->where('ticket_id', $this->ticketId)
                    ->where('logic_generation', '<=', $this->targetGeneration)
                    ->where('status', TicketEvent::STATUS_FAILED)
                    ->exists();
                $outbox->forceFill([
                    'state' => $failed ? 'blocked' : $outbox->state,
                    'last_error' => $failed ? 'One or more ticket events failed.' : null,
                ])->save();
                return false;
            }

            $hasNewGeneration = $outbox->requested_generation > $this->targetGeneration;
            $replayPending = $outbox->dispatch_kind === 'replay' && $hasNewGeneration;
            if (!$hasNewGeneration) {
                $idempotencyKey = implode(':', [
                    'sla-sync',
                    $this->ticketId,
                    $this->targetGeneration,
                    $this->syncEpoch,
                ]);
                FreshdeskOutboundOperation::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'operation_id' => (string) Str::uuid(),
                        'ticket_id' => $this->ticketId,
                        'operation_type' => 'sync_sla',
                        'coalesce_key' => 'sla-sync',
                        'generation' => $this->targetGeneration,
                        'sync_epoch' => $this->syncEpoch,
                        'operation_version' => 1,
                        'state' => 'ready',
                        'available_at' => now(),
                    ]
                );
            }
            $outbox->forceFill([
                'acked_generation' => max($outbox->acked_generation, $this->targetGeneration),
                'state' => $hasNewGeneration
                    ? ($replayPending ? 'replay_requested' : 'ready')
                    : 'completed',
                'dispatch_kind' => $hasNewGeneration && $replayPending ? 'replay' : 'normal',
                'available_at' => $hasNewGeneration ? now()->addSeconds(20) : $outbox->available_at,
                'lease_token' => null,
                'visibility_at' => null,
                'heartbeat_at' => now(),
                'last_error' => null,
            ])->save();

            $ticket->touch();
            return true;
        });
    }

    protected function dispatchReplayContinuation(): void
    {
        if ($this->outboxToken === null) {
            return;
        }

        $newToken = (string) Str::uuid();
        $rotated = TicketLogicOutbox::query()
            ->where('ticket_id', $this->ticketId)
            ->where('state', 'replaying')
            ->where('lease_token', $this->outboxToken)
            ->where('requested_generation', $this->targetGeneration)
            ->where('sync_epoch', $this->syncEpoch)
            ->update([
                'state' => 'replay_continue_dispatched',
                'lease_token' => $newToken,
                'visibility_at' => now()->addSeconds(150),
                'heartbeat_at' => now(),
            ]);

        if ($rotated !== 1) {
            return;
        }

        try {
            self::dispatch(
                $this->ticketId,
                true,
                true,
                $newToken,
                $this->targetGeneration,
                $this->syncEpoch
            )->onQueue('ticket-logic');
        } catch (\Throwable $exception) {
            TicketLogicOutbox::query()
                ->where('ticket_id', $this->ticketId)
                ->where('state', 'replay_continue_dispatched')
                ->where('lease_token', $newToken)
                ->update([
                    'visibility_at' => now()->addSeconds(5),
                    'last_error' => $exception->getMessage(),
                ]);
        }
    }

    protected function blockLogicOutbox(string $reason): void
    {
        if ($this->outboxToken === null) {
            return;
        }

        TicketLogicOutbox::query()
            ->where('ticket_id', $this->ticketId)
            ->where('lease_token', $this->outboxToken)
            ->where('sync_epoch', $this->syncEpoch)
            ->update([
                'state' => 'blocked',
                'visibility_at' => null,
                'last_error' => $reason,
            ]);
    }
}
