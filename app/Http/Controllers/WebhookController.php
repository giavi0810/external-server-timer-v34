<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTicketEventJob;
use App\Models\FreshdeskGroup;
use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use App\Services\Sla\BatchTicketEventService;
use App\Http\Requests\BatchFreshdeskWebhookRequest;
use App\Http\Requests\FreshdeskWebhookRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected ?FreshdeskApiService $freshdeskService;

    public function __construct(?FreshdeskApiService $freshdeskService = null)
    {
        $this->freshdeskService = $freshdeskService;
    }

    /**
     * Handle incoming Freshdesk webhook events.
     */
    public function handleFreshdeskTicketEvent(FreshdeskWebhookRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $ticketId = $validated['ticket_id'];

            if (Cache::has("migration_lock_{$ticketId}")) {
                Log::info("Migration Lock: Webhook ignored for ticket {$ticketId}");
                return response()->json([
                    'success' => true,
                    'ignored' => true,
                    'reason' => 'migration_in_progress',
                ], 200);
            }

            // Phase 1 Legacy Ticket Filtering: Ignore tickets created before Go-Live
            if (env('ENABLE_LEGACY_TICKET_FILTER') && env('GO_LIVE_TIMESTAMP')) {
                $ticketData = $validated['ticket_data'] ?? [];
                $ticketCreatedAtRaw = $ticketData['created_at']
                    ?? $request->input('raw_payload.ticket.created_at')
                    ?? $request->input('ticket.created_at')
                    ?? null;

                if ($ticketCreatedAtRaw) {
                    $ticketCreatedAt = Carbon::parse($ticketCreatedAtRaw);
                    $goLiveTimestamp = Carbon::parse(env('GO_LIVE_TIMESTAMP'));

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



            $eventType = $validated['event_type'];

            $eventTimestamp = $validated['event_timestamp'];
            $ticketData = $validated['ticket_data'] ?? [];
            $changes = $validated['changes'] ?? [];

            if (in_array($eventType, [TicketEvent::EVENT_AGENT_REPLIED, TicketEvent::EVENT_REQUESTER_REPLIED]) && !empty($validated['conversation_data']['updated_at'])) {
                $eventTimestamp = Carbon::parse($validated['conversation_data']['updated_at'])->toISOString();
            } elseif (!empty($ticketData['updated_at'])) {
                $eventTimestamp = Carbon::parse($ticketData['updated_at'])->toISOString();
            }

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
                    'group_name' => $this->freshdeskService?->resolveGroupName($ticket->group_id),
                    'requester_id' => $ticket->requester_id,
                    'created_at' => $ticket->fd_created_at,
                    'updated_at' => Carbon::parse($eventTimestamp)->toISOString(),
                ];
            }

            $status = $ticketData['status'] ?? null;
            if (is_numeric($status)) {
                $status = config("freshdesk.status_map.{$status}", $status);
            }

            $priority = $ticketData['priority'] ?? null;
            if (is_numeric($priority)) {
                $priority = config("freshdesk.priority_map.{$priority}", $priority);
            }

            $groupId = $this->normalizeGroupIdValue(
                $ticketData['group_id'] ?? $this->freshdeskService?->resolveGroupId($ticketData['group_name'] ?? null)
            );
            $groupName = $ticketData['group_name'] ?? null;

            if (!$groupName && $groupId) {
                $groupName = $this->freshdeskService?->resolveGroupName($groupId);
            }

            if ($groupId) {
                FreshdeskGroup::updateOrCreate(
                    ['group_id' => $groupId],
                    [
                        'name' => $groupName ?: "Freshdesk Group {$groupId}",
                        'main_layer' => config("freshdesk.group_layers.{$groupName}", 'L1'),
                        'is_active' => true,
                    ]
                );
            }

            Log::debug("WebhookController: Raw Payload Received", [
                'ticket_id' => $ticketId,
                'event_type' => $eventType,
                'payload' => $request->all()
            ]);

            $allCf = $request->input('raw_payload.ticket.custom_fields')
                ?? $request->input('raw_payload.custom_fields')
                ?? $request->input('ticket_data.custom_fields')
                ?? $request->input('ticket.custom_fields')
                ?? $ticketData['custom_fields']
                ?? [];

            $whitelistKeys = [
                'cf_sla_mode',
                'cf_number_of_due_date_changes',
                'cf_processing_phase',
                'cf_change_due_reason'
            ];

            $cf = [];
            foreach ($allCf as $key => $value) {
                foreach ($whitelistKeys as $whiteKey) {
                    if (str_starts_with($key, $whiteKey)) {
                        $cf[$key] = $value;
                        break;
                    }
                }
            }

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

            if (!empty($validated['ticket_data'])) {
                $ticketUpdateData = [
                    'subject' => $ticketData['subject'] ?? null,
                    'status' => $status ?? 'Open',
                    'priority' => $priority ?? 'Medium',
                    'ticket_type' => $ticketData['ticket_type'] ?? 'VIP',
                    'group_id' => $groupId,
                    'requester_id' => $ticketData['requester_id'] ?? $validated['conversation_data']['actor_id'] ?? null,
                    'fd_created_at' => $ticketData['created_at'] ?? now(),
                ];

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

            Log::info("WebhookController: Ticket State Saved", [
                'ticket_id' => $ticket->ticket_id,
                'sla_mode' => $ticket->getOrCreateTtrMetric()->sla_mode,
                'priority' => $ticket->priority
            ]);

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
                'agent_reply_count' => $validated['raw_payload']['ticket']['agent_reply_count'] ?? null,
                'customer_reply_count' => $validated['raw_payload']['ticket']['customer_reply_count'] ?? null,
                'agent_responded_at' => $validated['raw_payload']['ticket']['agent_responded_at'] ?? null,
                'requester_responded_at' => $validated['raw_payload']['ticket']['requester_responded_at'] ?? null,
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

        } catch (\Exception $e) {
            Log::error("Webhook processing error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
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

    public function handleBatchEvents(
        BatchFreshdeskWebhookRequest $request,
        BatchTicketEventService $batchService
    ): JsonResponse
    {
        try {
            $result = $batchService->ingest($request->validated('events'));

            return response()->json([
                'success' => true,
                'message' => 'Batch sự kiện đã được tiếp nhận.',
                'data' => $result,
            ], 202);
        } catch (\Throwable $exception) {
            Log::error('Batch webhook ingestion failed', [
                'events_count' => count($request->validated('events')),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể tiếp nhận batch sự kiện.',
            ], 500);
        }
    }
}
