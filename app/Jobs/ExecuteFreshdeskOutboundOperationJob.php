<?php

namespace App\Jobs;

use App\Models\FreshdeskOutboundOperation;
use App\Models\Ticket;
use App\Models\TicketLogicOutbox;
use App\Exceptions\UncertainFreshdeskOutcomeException;
use App\Services\Sla\AppTimerSyncService;
use App\Services\FreshdeskApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ExecuteFreshdeskOutboundOperationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 105;
    public int $tries = 1;

    public function __construct(
        public readonly string $operationId,
        public readonly string $leaseToken,
        public readonly int $generation,
        public readonly int $syncEpoch,
        public readonly int $operationVersion
    ) {
    }

    public function handle(AppTimerSyncService $syncService, FreshdeskApiService $freshdesk): void
    {
        $claimed = FreshdeskOutboundOperation::query()
            ->whereKey($this->operationId)
            ->where('state', 'dispatched')
            ->where('lease_token', $this->leaseToken)
            ->where('generation', $this->generation)
            ->where('sync_epoch', $this->syncEpoch)
            ->where('operation_version', $this->operationVersion)
            ->update([
                'state' => 'processing',
                'visibility_at' => now()->addSeconds(120),
            ]);
        if ($claimed !== 1) {
            return;
        }

        $operation = FreshdeskOutboundOperation::query()->findOrFail($this->operationId);
        $lock = Cache::lock("ticket_processing:{$operation->ticket_id}", 120);
        if (!$lock->get()) {
            $this->deferClaim('Ticket processing lock is busy.', 15);
            return;
        }

        try {
            $replayActive = TicketLogicOutbox::query()
                ->where('ticket_id', $operation->ticket_id)
                ->whereIn('state', [
                    'replay_requested', 'replay_start_dispatched',
                    'replay_initializing', 'replaying', 'replay_continue_dispatched',
                ])
                ->exists();
            if ($replayActive) {
                $this->deferClaim('Outbound operation blocked by replay.', 30);
                return;
            }

            $ticket = Ticket::query()->where('ticket_id', $operation->ticket_id)->firstOrFail();
            $remoteId = match ($operation->operation_type) {
                'sync_sla' => $this->syncSla($syncService, $ticket),
                'reopen_ticket' => $this->reopenTicket($freshdesk, $operation),
                'create_followup_ticket' => $this->createFollowupTicket($freshdesk, $operation),
                'change_due_date' => $this->changeDueDate($freshdesk, $operation),
                default => throw new \RuntimeException(
                    "Unsupported outbound operation: {$operation->operation_type}"
                ),
            };

            FreshdeskOutboundOperation::query()
                ->whereKey($this->operationId)
                ->where('state', 'processing')
                ->where('lease_token', $this->leaseToken)
                ->where('generation', $this->generation)
                ->where('sync_epoch', $this->syncEpoch)
                ->where('operation_version', $this->operationVersion)
                ->update([
                    'state' => 'completed',
                    'completed_at' => now(),
                    'lease_token' => null,
                    'visibility_at' => null,
                    'last_error' => null,
                    'remote_id' => $remoteId,
                ]);
        } catch (UncertainFreshdeskOutcomeException $exception) {
            FreshdeskOutboundOperation::query()
                ->whereKey($this->operationId)
                ->where('state', 'processing')
                ->where('lease_token', $this->leaseToken)
                ->where('generation', $this->generation)
                ->where('sync_epoch', $this->syncEpoch)
                ->where('operation_version', $this->operationVersion)
                ->update([
                    'state' => 'ready',
                    'reconcile_only' => true,
                    'lease_token' => null,
                    'visibility_at' => null,
                    'available_at' => now()->addMinutes(2),
                    'last_error' => $exception->getMessage(),
                ]);
        } catch (\Throwable $exception) {
            $attemptCount = (int) FreshdeskOutboundOperation::query()
                ->whereKey($this->operationId)
                ->value('attempt_count');
            FreshdeskOutboundOperation::query()
                ->whereKey($this->operationId)
                ->where('state', 'processing')
                ->where('lease_token', $this->leaseToken)
                ->where('generation', $this->generation)
                ->where('sync_epoch', $this->syncEpoch)
                ->where('operation_version', $this->operationVersion)
                ->update([
                    'state' => 'ready',
                    'lease_token' => null,
                    'visibility_at' => null,
                    'available_at' => now()->addSeconds(min(600, 15 * (2 ** min(5, $attemptCount)))),
                    'last_error' => $exception->getMessage(),
                ]);
            Log::warning('Freshdesk outbound operation deferred', [
                'operation_id' => $this->operationId,
                'reason' => $exception->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function syncSla(AppTimerSyncService $syncService, Ticket $ticket): string
    {
        $syncService->syncTicket($ticket);
        return (string) $ticket->ticket_id;
    }

    private function reopenTicket(
        FreshdeskApiService $freshdesk,
        FreshdeskOutboundOperation $operation
    ): string {
        $marker = $this->marker();
        $remote = $freshdesk->getTicket($operation->ticket_id);
        if (!is_array($remote)) {
            throw new \RuntimeException('Unable to reconcile Freshdesk ticket before reopen.');
        }

        $tags = is_array($remote['tags'] ?? null) ? $remote['tags'] : [];
        if (in_array($marker, $tags, true)) {
            return (string) $operation->ticket_id;
        }

        $maxReopen = 0;
        $filtered = [];
        foreach ($tags as $tag) {
            if (preg_match('/^Reopened(?:[\s_]*\(?(\d+)\)?)?$/i', trim((string) $tag), $matches)) {
                $maxReopen = max($maxReopen, isset($matches[1]) ? (int) $matches[1] : 1);
            } else {
                $filtered[] = $tag;
            }
        }
        $filtered[] = 'Reopened ('.($maxReopen + 1).')';
        $filtered[] = $marker;
        $payload = ['status' => 3, 'tags' => array_values(array_unique($filtered))];
        if (!empty($operation->payload['group_id'])) {
            $payload['group_id'] = $operation->payload['group_id'];
        }
        if (!$freshdesk->updateTicket($operation->ticket_id, $payload)) {
            throw new \RuntimeException('Freshdesk reopen PUT failed.');
        }

        return (string) $operation->ticket_id;
    }

    private function createFollowupTicket(
        FreshdeskApiService $freshdesk,
        FreshdeskOutboundOperation $operation
    ): string {
        $marker = $this->marker();
        $existing = $freshdesk->findTicketByOperationMarker($marker);
        $remoteId = $existing['id'] ?? null;

        if (!$remoteId) {
            if ($operation->reconcile_only) {
                throw new UncertainFreshdeskOutcomeException(
                    'Freshdesk marker is not visible yet; POST will not be repeated blindly.'
                );
            }
            $payload = $operation->payload['create_payload'] ?? [];
            $payload['tags'] = array_values(array_unique(array_merge($payload['tags'] ?? [], [$marker])));
            $created = $freshdesk->createTicket($payload);
            $remoteId = $created['id'] ?? null;
            if (!$remoteId) {
                $context = $freshdesk->getLastErrorContext() ?? [];
                if (($context['outcome_unknown'] ?? false) === true) {
                    throw new UncertainFreshdeskOutcomeException(
                        'Freshdesk follow-up POST outcome requires reconciliation.'
                    );
                }
                throw new \RuntimeException('Freshdesk follow-up POST failed definitively.');
            }
        }

        if (($operation->payload['set_due_driven'] ?? false)
            && !empty($operation->payload['processing_mode_key'])
            && !$freshdesk->updateTicket((int) $operation->payload['source_ticket_id'], [
                'custom_fields' => [$operation->payload['processing_mode_key'] => 'due-driven'],
            ])
        ) {
            throw new \RuntimeException(
                'Freshdesk source ticket update failed after follow-up creation.'
            );
        }

        return (string) $remoteId;
    }

    private function changeDueDate(
        FreshdeskApiService $freshdesk,
        FreshdeskOutboundOperation $operation
    ): string {
        $marker = $this->marker();
        $remote = $freshdesk->getTicket($operation->ticket_id);
        if (!is_array($remote)) {
            throw new \RuntimeException('Unable to reconcile Freshdesk ticket before Due Date update.');
        }

        $tags = is_array($remote['tags'] ?? null) ? $remote['tags'] : [];
        if (in_array($marker, $tags, true)) {
            return (string) $operation->ticket_id;
        }

        $customFields = is_array($remote['custom_fields'] ?? null)
            ? $remote['custom_fields']
            : [];
        $countKey = $this->customFieldKey(
            $customFields,
            ['cf_number_of_due_date_changes'],
            'cf_number_of_due_date_changes'
        );
        $processingModeKey = $this->customFieldKey(
            $customFields,
            ['cf_processing_mode', 'cf_sla_mode'],
            'cf_processing_mode'
        );
        $phaseKey = $this->customFieldKey(
            $customFields,
            ['cf_processing_phase'],
            'cf_processing_phase'
        );
        $reasonKey = $this->customFieldKey(
            $customFields,
            ['cf_change_due_reason'],
            'cf_change_due_reason'
        );
        $nextCount = max(0, (int) ($customFields[$countKey] ?? 0)) + 1;
        $dueDate = (string) ($operation->payload['new_due_date'] ?? '');
        if ($dueDate === '') {
            throw new \RuntimeException('Due Date outbound operation is missing new_due_date.');
        }

        $updatedFields = [
            $countKey => $nextCount,
            $processingModeKey => 'due-driven',
        ];
        if (!empty($operation->payload['processing_phase'])) {
            $updatedFields[$phaseKey] = $operation->payload['processing_phase'];
        }
        if (!empty($operation->payload['reason'])) {
            $updatedFields[$reasonKey] = $operation->payload['reason'];
        }

        $tagPattern = '/^due_date_change(?:\s*\((\d+)\))?$/i';
        $filteredTags = array_values(array_filter(
            $tags,
            static fn (mixed $tag): bool => !preg_match($tagPattern, trim((string) $tag))
        ));
        $dueDateTag = "due_date_change ({$nextCount})";
        $filteredTags[] = $dueDateTag;
        $filteredTags[] = $marker;

        if (!$freshdesk->updateTicket($operation->ticket_id, [
            'due_by' => $dueDate,
            'custom_fields' => $updatedFields,
            'tags' => array_values(array_unique($filteredTags)),
        ])) {
            throw new \RuntimeException('Freshdesk Due Date PUT failed.');
        }

        $noteLines = [
            "Thay đổi Due Date lần {$nextCount}",
            "- Due Date mới: {$dueDate}",
            '- Processing Mode: due-driven',
            "- Tag: {$dueDateTag}",
            "- Operation: {$marker}",
        ];
        if (!empty($operation->payload['processing_phase'])) {
            $noteLines[] = '- Processing Phase: '.$operation->payload['processing_phase'];
        }
        if (!empty($operation->payload['reason'])) {
            $noteLines[] = '- Lý do: '.$operation->payload['reason'];
        }
        if (!empty($operation->payload['agent_name'])) {
            $noteLines[] = '- Người thực hiện: '.$operation->payload['agent_name'];
        }

        if (!$freshdesk->addTicketNote(
            $operation->ticket_id,
            implode("\n", $noteLines),
            true
        )) {
            Log::warning('Freshdesk Due Date updated but audit note could not be added', [
                'operation_id' => $operation->operation_id,
                'ticket_id' => $operation->ticket_id,
            ]);
        }

        return (string) $operation->ticket_id;
    }

    private function customFieldKey(array $customFields, array $prefixes, string $fallback): string
    {
        foreach (array_keys($customFields) as $key) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $key, $prefix)) {
                    return (string) $key;
                }
            }
        }

        return $fallback;
    }

    private function marker(): string
    {
        return 'v34op-'.substr(str_replace('-', '', $this->operationId), 0, 20);
    }

    private function deferClaim(string $reason, int $seconds): void
    {
        FreshdeskOutboundOperation::query()
            ->whereKey($this->operationId)
            ->where('state', 'processing')
            ->where('lease_token', $this->leaseToken)
            ->where('generation', $this->generation)
            ->where('sync_epoch', $this->syncEpoch)
            ->where('operation_version', $this->operationVersion)
            ->update([
                'state' => 'ready',
                'lease_token' => null,
                'visibility_at' => null,
                'available_at' => now()->addSeconds($seconds),
                'last_error' => $reason,
            ]);
    }
}
