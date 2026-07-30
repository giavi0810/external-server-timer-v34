<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWebhookPayloadException;
use App\Jobs\ProcessTicketEventJob;
use App\Models\FreshdeskGroup;
use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use App\Services\Sla\BatchTicketEventService;
use App\Services\Webhooks\FreshdeskEventNormalizer;
use App\Http\Requests\BatchFreshdeskWebhookRequest;
use App\Http\Requests\FreshdeskWebhookRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(
        protected FreshdeskApiService $freshdeskService,
        protected FreshdeskEventNormalizer $eventNormalizer
    ) {
    }

    /**
     * Handle incoming Freshdesk webhook events.
     */
    public function handleFreshdeskTicketEvent(FreshdeskWebhookRequest $request): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request->header('X-Correlation-ID'));

        try {
            $validated = $request->validated();
            $ticketId = (int) $validated['ticket_id'];

            if (Cache::has("migration_lock_{$ticketId}")) {
                Log::info("Migration Lock: Webhook ignored for ticket {$ticketId}");
                return response()->json([
                    'success' => true,
                    'ignored' => true,
                    'reason' => 'migration_in_progress',
                ], 200);
            }

            // Phase 1 Legacy Ticket Filtering: Ignore tickets created before Go-Live
            if (config('freshdesk.enable_legacy_ticket_filter') && config('freshdesk.go_live_timestamp')) {
                $ticketData = $validated['ticket_data'] ?? [];
                $ticketCreatedAtRaw = $ticketData['created_at']
                    ?? $request->input('raw_payload.ticket.created_at')
                    ?? $request->input('ticket.created_at')
                    ?? null;

                if ($ticketCreatedAtRaw) {
                    $ticketCreatedAt = Carbon::parse($ticketCreatedAtRaw);
                    $goLiveTimestamp = Carbon::parse(config('freshdesk.go_live_timestamp'));

                    if ($ticketCreatedAt->lessThan($goLiveTimestamp)) {
                        Log::info("Webhook Phase 1 Filter: Ignored legacy ticket created before Go-Live", [
                            'ticket_id' => $ticketId,
                            'created_at' => $ticketCreatedAt->toIso8601String(),
                            'go_live_timestamp' => $goLiveTimestamp->toIso8601String(),
                        ]);

                        return response()->json([
                            'success' => true,
                            'ignored' => true,
                            'reason' => 'legacy_ticket_phase_1',
                        ], 200);
                    }
                }
            }


            $normalizedEvent = $this->eventNormalizer->normalize(array_merge(
                $validated,
                ['ticket' => $request->input('ticket')]
            ));
            $eventType = $normalizedEvent['event_type'];
            $eventTimestamp = $normalizedEvent['event_timestamp'];
            $ticketData = $normalizedEvent['ticket_data'];
            $changes = $normalizedEvent['changes'];
            $hasIncomingTicketData = $request->has('ticket_data')
                || $request->has('raw_payload.ticket')
                || $request->has('ticket');

            if (!TicketEvent::isSupportedType($eventType)) {
                Log::info("Webhook ignored: unsupported event type", [
                    'event_type' => $eventType,
                    'ticket_id' => $ticketId,
                ]);

                return response()->json([
                    'success' => true,
                    'ignored' => true,
                    'reason' => 'unsupported_event_type',
                ], 200);
            }

            $ticket = Ticket::where('ticket_id', $ticketId)->first();
            $oldValues = $ticket ? $ticket->toArray() : [];

            if (empty($ticketData) && $ticket) {
                Log::info("ticket_data is empty, using existing ticket from DB", [
                    'ticket_id' => $ticketId,
                ]);
                $ticketData = [
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'ticket_type' => $ticket->ticket_type,
                    'group_id' => $ticket->group_id,
                    'group_name' => $this->freshdeskService->resolveGroupName($ticket->group_id),
                    'requester_id' => $ticket->requester_id,
                    'created_at' => $ticket->fd_created_at,
                    'updated_at' => Carbon::parse($eventTimestamp)->toISOString(),
                ];
            }

            $status = $ticketData['status'] ?? null;
            $priority = $ticketData['priority'] ?? null;

            $groupId = $this->normalizeGroupIdValue(
                $ticketData['group_id'] ?? $this->freshdeskService->resolveGroupId($ticketData['group_name'] ?? null)
            );
            $groupName = $ticketData['group_name'] ?? null;

            if ($groupId && !$groupName) {
                $groupName = $this->freshdeskService->resolveGroupName($groupId);
            }



            Log::debug("WebhookController: Payload received", [
                'ticket_id' => $ticketId,
                'event_type' => $eventType,
                'correlation_id' => $correlationId,
                'top_level_keys' => array_keys($request->all()),
            ]);

            $cf = $ticketData['custom_fields'] ?? [];

            $slaModeFromWebhook = null;
            foreach ($cf as $k => $v) {
                if (str_starts_with($k, 'cf_sla_mode')) {
                    $slaModeFromWebhook = $v;
                    break;
                }
            }

            $isDueDriven = ($slaModeFromWebhook === 'due-driven');

            if ($slaModeFromWebhook !== null) {
                Log::info("WebhookController: Detected SLA mode", [
                    'ticket_id' => $ticketId,
                    'sla_mode' => $slaModeFromWebhook,
                    'is_due_driven' => $isDueDriven
                ]);
            }

            if ($hasIncomingTicketData && !empty($ticketData)) {
                $incomingData = [
                    'subject' => $ticketData['subject'] ?? null,
                    'status' => $status,
                    'priority' => $priority,
                    'ticket_type' => $ticketData['ticket_type'] ?? null,
                    'group_id' => $groupId,
                    'requester_id' => $ticketData['requester_id'] ?? $validated['conversation_data']['actor_id'] ?? null,
                    'fd_created_at' => $ticketData['created_at'] ?? null,
                ];

                $ticketUpdateData = array_filter($incomingData, static fn ($value) => $value !== null);

                $ticket = Ticket::updateOrCreate(
                    ['ticket_id' => $ticketId],
                    $ticketUpdateData
                );

                $ticket->refresh();
                if ($slaModeFromWebhook !== null) {
                    $ticket->getOrCreateTtrMetric()->update([
                        'sla_mode' => $isDueDriven ? 'due-driven' : 'priority-driven',
                    ]);
                }
            } elseif ($ticket && $slaModeFromWebhook !== null) {
                $ticket->getOrCreateTtrMetric()->update([
                    'sla_mode' => $isDueDriven ? 'due-driven' : 'priority-driven',
                ]);

                Log::info("WebhookController: Updated only due-driven flag", [
                    'ticket_id' => $ticketId,
                    'flag' => $isDueDriven
                ]);
            }

            if ($ticket) {
                Log::info("WebhookController: Ticket State Saved", [
                    'ticket_id' => $ticket->ticket_id,
                    'sla_mode' => $ticket->getOrCreateTtrMetric()->sla_mode,
                    'priority' => $ticket->priority
                ]);
            }


            $normalizedChanges = [];

            if (!empty($changes) && isset($changes[0]['field'])) {
                $normalizedChanges = $changes;
            } else {
                $fieldsToTrack = [
                    'status' => $status,
                    'priority' => $priority,
                    'group_id' => $groupId,
                ];

                foreach ($fieldsToTrack as $field => $newValue) {
                    $oldValue = $oldValues[$field] ?? null;
                    if ($newValue !== null && $newValue !== '' && $oldValue !== null && $oldValue !== '' && $newValue != $oldValue) {
                        $normalizedChanges[] = [
                            'field' => $field,
                            'old_value' => $oldValue,
                            'new_value' => $newValue
                        ];
                    }
                }
            }

            $normalizedChanges = $this->canonicalizeGroupChanges($normalizedChanges, $groupId, $oldValues);

            foreach ($normalizedChanges as &$change) {
                if ($change['field'] === 'status') {
                    if (is_numeric($change['old_value'] ?? null)) {
                        $change['old_value'] = config("freshdesk.status_map.{$change['old_value']}", $change['old_value']);
                    }
                    if (is_numeric($change['new_value'] ?? null)) {
                        $change['new_value'] = config("freshdesk.status_map.{$change['new_value']}", $change['new_value']);
                    }
                }
                if ($change['field'] === 'priority') {
                    if (is_numeric($change['old_value'] ?? null)) {
                        $change['old_value'] = config("freshdesk.priority_map.{$change['old_value']}", $change['old_value']);
                    }
                    if (is_numeric($change['new_value'] ?? null)) {
                        $change['new_value'] = config("freshdesk.priority_map.{$change['new_value']}", $change['new_value']);
                    }
                }
            }
            unset($change);

            $enrichedTicketData = array_merge($ticketData, [
                'id' => $ticketId,
                'status' => $status,
                'priority' => $priority,
                'group_id' => $groupId,
                'group_name' => $groupName,
                'requester_id' => $ticketData['requester_id'] ?? $ticket?->requester_id ?? $validated['conversation_data']['actor_id'] ?? null,
                'custom_fields' => $cf,
                'agent_reply_count' => $normalizedEvent['raw_payload']['ticket']['agent_reply_count'] ?? null,
                'customer_reply_count' => $normalizedEvent['raw_payload']['ticket']['customer_reply_count'] ?? null,
                'agent_responded_at' => $normalizedEvent['raw_payload']['ticket']['agent_responded_at'] ?? null,
                'requester_responded_at' => $normalizedEvent['raw_payload']['ticket']['requester_responded_at'] ?? null,
            ]);

            $eventDataPayload = ['ticket_data' => $enrichedTicketData];
            if (!empty($validated['conversation_data'])) {
                $eventDataPayload['conversation_data'] = $validated['conversation_data'];
            }

            if ($eventType === TicketEvent::EVENT_AGENT_REPLIED) {
                return $this->storeAgentReplyTicketEventWithLock(
                    $ticketId,
                    $eventType,
                    $eventTimestamp,
                    $eventDataPayload,
                    $normalizedChanges,
                    $validated['conversation_data']['actor_id'] ?? 'none'
                );
            }

            $existingTicketEvent = TicketEvent::query()
                ->where('ticket_id', $ticketId)
                ->where('event_type', $eventType)
                ->where('event_timestamp', Carbon::parse($eventTimestamp))
                ->first();

            if ($existingTicketEvent) {
                Log::info("Webhook duplicate ignored", [
                    'event_id' => $existingTicketEvent->id,
                    'event_type' => $eventType,
                    'ticket_id' => $ticketId,
                ]);

                return response()->json([
                    'success' => true,
                    'event_id' => $existingTicketEvent->id,
                    'duplicate' => true,
                ], 200);
            }

            $event = $this->createQueuedTicketEvent(
                $ticketId,
                $eventType,
                $eventDataPayload,
                $normalizedChanges,
                $eventTimestamp,
                $validated['conversation_data']['actor_id'] ?? 'none'
            );

            return response()->json([
                'success' => true,
                'event_id' => $event->id,
            ], 200);

        } catch (InvalidWebhookPayloadException $exception) {
            Log::warning('Webhook payload rejected', [
                'ticket_id' => $request->integer('ticket_id') ?: null,
                'correlation_id' => $correlationId,
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook payload',
                'reason' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ], 422);
        } catch (\Throwable $e) {
            Log::error("Webhook processing error", [
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ticket_id' => $request->integer('ticket_id') ?: null,
                'correlation_id' => $correlationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    protected function createQueuedTicketEvent(
        int $ticketId,
        string $eventType,
        array $eventDataPayload,
        array $normalizedChanges,
        string $eventTimestamp,
        mixed $actor
    ): TicketEvent {
        $event = TicketEvent::create([
            'ticket_id' => $ticketId,
            'idempotency_key' => TicketEvent::makeIdempotencyKey($ticketId, $eventType, $eventTimestamp, $actor),
            'event_type' => $eventType,
            'event_data' => $eventDataPayload,
            'field_changes' => $normalizedChanges,
            'status' => TicketEvent::STATUS_PENDING,
            'event_timestamp' => $eventTimestamp,
            'received_at' => now(),
        ]);

        ProcessTicketEventJob::dispatch($ticketId)->delay(now()->addSeconds(20));
        $event->markAsQueued();

        Log::info("Webhook received and queued", [
            'event_id' => $event->id,
            'event_type' => $eventType,
            'ticket_id' => $ticketId,
            'actor' => $actor,
            'changes_count' => count($normalizedChanges),
            'normalized_changes' => $normalizedChanges,
        ]);

        return $event;
    }

    protected function storeAgentReplyTicketEventWithLock(
        int $ticketId,
        string $eventType,
        string $eventTimestamp,
        array $eventDataPayload,
        array $normalizedChanges,
        mixed $actor
    ): JsonResponse {
        try {
            return Cache::lock("webhook_agent_replied_store:{$ticketId}", 10)
                ->block(5, function () use ($ticketId, $eventType, $eventTimestamp, $eventDataPayload, $normalizedChanges, $actor): JsonResponse {
                    $existingTicketEvent = $this->findDuplicateAgentReplyTicketEvent(
                        $ticketId,
                        $eventTimestamp,
                        $eventDataPayload
                    );

                    if ($existingTicketEvent) {
                        $existingTicketEvent->event_data = $this->mergeTicketEventDataPreservingNonBlank(
                            is_array($existingTicketEvent->event_data) ? $existingTicketEvent->event_data : [],
                            $eventDataPayload
                        );

                        $existingTicketEvent->save();

                        Log::info("Webhook duplicate ignored for agent_replied", [
                            'event_id' => $existingTicketEvent->id,
                            'event_type' => $eventType,
                            'ticket_id' => $ticketId,
                            'event_timestamp' => $eventTimestamp,
                        ]);

                        return response()->json([
                            'success' => true,
                            'event_id' => $existingTicketEvent->id,
                            'duplicate' => true,
                        ], 200);
                    }

                    $event = $this->createQueuedTicketEvent(
                        $ticketId,
                        $eventType,
                        $eventDataPayload,
                        $normalizedChanges,
                        $eventTimestamp,
                        $actor
                    );

                    return response()->json([
                        'success' => true,
                        'event_id' => $event->id,
                    ], 200);
                });
        } catch (LockTimeoutException $exception) {
            Log::warning("Timed out waiting for agent_replied dedupe lock", [
                'ticket_id' => $ticketId,
                'event_timestamp' => $eventTimestamp,
            ]);

            throw $exception;
        }
    }

    protected function mergeTicketEventDataPreservingNonBlank(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value)) {
                $existing[$key] = $this->mergeTicketEventDataPreservingNonBlank(
                    is_array($existing[$key] ?? null) ? $existing[$key] : [],
                    $value
                );
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    protected function findDuplicateAgentReplyTicketEvent(int $ticketId, string $eventTimestamp, array $eventDataPayload): ?TicketEvent
    {
        $conversationData = $eventDataPayload['conversation_data'] ?? [];
        $incomingActorId = trim((string) ($conversationData['actor_id'] ?? ''));
        $incomingActorType = strtolower(trim((string) ($conversationData['actor_type'] ?? '')));

        if ($incomingActorId === '' && $incomingActorType === '') {
            return null;
        }

        return TicketEvent::query()
            ->where('ticket_id', $ticketId)
            ->where('event_type', TicketEvent::EVENT_AGENT_REPLIED)
            ->where('event_timestamp', Carbon::parse($eventTimestamp))
            ->orderBy('id')
            ->get()
            ->first(function (TicketEvent $event) use ($incomingActorId, $incomingActorType): bool {
                $existingConversationData = $event->event_data['conversation_data'] ?? [];
                $existingActorId = trim((string) ($existingConversationData['actor_id'] ?? ''));
                $existingActorType = strtolower(trim((string) ($existingConversationData['actor_type'] ?? '')));

                return $existingActorId === $incomingActorId
                    && $existingActorType === $incomingActorType;
            });
    }

    protected function canonicalizeGroupChanges(array $changes, ?string $groupId, array $oldValues): array
    {
        $canonicalChanges = [];
        $canonicalGroupChange = null;

        foreach ($changes as $change) {
            $field = $change['field'] ?? null;

            if ($field === 'group_id') {
                $canonicalGroupChange = [
                    'field' => 'group_id',
                    'old_value' => $this->normalizeGroupIdValue($change['old_value'] ?? ($oldValues['group_id'] ?? null)),
                    'new_value' => $this->normalizeGroupIdValue($change['new_value'] ?? $groupId),
                ];
                continue;
            }

            if ($field === 'group_name') {
                if ($canonicalGroupChange === null) {
                    $canonicalGroupChange = [
                        'field' => 'group_id',
                        'old_value' => $this->normalizeGroupIdValue(
                            $this->freshdeskService?->resolveGroupId($change['old_value'] ?? null) ?? ($oldValues['group_id'] ?? null)
                        ),
                        'new_value' => $this->normalizeGroupIdValue(
                            $this->freshdeskService?->resolveGroupId($change['new_value'] ?? null) ?? $groupId
                        ),
                    ];
                }
                continue;
            }

            $canonicalChanges[] = $change;
        }

        if ($canonicalGroupChange !== null) {
            $canonicalChanges[] = $canonicalGroupChange;
        }

        return $canonicalChanges;
    }

    protected function normalizeGroupIdValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalizedValue = trim((string) $value);

        return $normalizedValue === '' ? null : $normalizedValue;
    }

    protected function resolveCorrelationId(?string $candidate): string
    {
        if ($candidate !== null && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }

    public function handleBatchEvents(
        BatchFreshdeskWebhookRequest $request,
        BatchTicketEventService $batchService
    ): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request->header('X-Correlation-ID'));

        try {
            $result = $batchService->ingest($request->validated('events'));

            foreach ($result['results'] as $itemResult) {
                if (($itemResult['status'] ?? null) !== 'rejected') {
                    continue;
                }

                Log::warning('Batch recovery record rejected', [
                    'recovery_id' => $itemResult['recovery_id'] ?? null,
                    'reason' => $itemResult['reason'] ?? 'unknown',
                    'correlation_id' => $correlationId,
                ]);
            }

            return response()->json([
                'success' => true,
                'correlation_id' => $correlationId,
                'message' => 'Batch sự kiện đã được tiếp nhận.',
                'data' => $result,
            ], 202);
        } catch (InvalidWebhookPayloadException $exception) {
            Log::warning('Batch webhook payload rejected', [
                'events_count' => count($request->validated('events')),
                'reason' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu batch không hợp lệ.',
                'reason' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Batch webhook ingestion failed', [
                'events_count' => count($request->validated('events')),
                'error' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'correlation_id' => $correlationId,
            ]);

            return response()->json([
                'success' => false,
                'correlation_id' => $correlationId,
                'message' => 'Không thể tiếp nhận batch sự kiện.',
            ], 500);
        }
    }
}
