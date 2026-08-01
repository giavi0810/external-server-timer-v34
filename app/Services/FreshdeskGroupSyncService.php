<?php

namespace App\Services;

use App\Models\FreshdeskGroup;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FreshdeskGroupSyncService
{
    private const LOCK_KEY = 'freshdesk-groups:refresh';

    private const LOCK_SECONDS = 45;

    private const WAIT_SECONDS = 35;

    public function __construct(private readonly FreshdeskApiService $freshdesk) {}

    public function ensurePayloadGroupsKnown(array $payload): void
    {
        $this->ensurePayloadsGroupsKnown([$payload]);
    }

    public function ensurePayloadsGroupsKnown(array $payloads): void
    {
        $groupIds = [];
        foreach ($payloads as $payload) {
            if (is_array($payload)) {
                array_push($groupIds, ...$this->extractGroupIds($payload));
            }
        }

        foreach (array_values(array_unique($groupIds)) as $groupId) {
            $this->ensureGroupKnown($groupId);
        }
    }

    public function ensureGroupKnown(string $groupId): void
    {
        if ($this->isKnownAndActive($groupId)) {
            return;
        }

        try {
            Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)
                ->block(self::WAIT_SECONDS, function () use ($groupId): void {
                    if ($this->isKnownAndActive($groupId)) {
                        return;
                    }

                    Log::notice('Unknown Freshdesk group detected; refreshing group mappings', [
                        'group_id' => $groupId,
                    ]);
                    $this->freshdesk->refreshGroupMappings();

                    if (! $this->isKnownAndActive($groupId)) {
                        throw new RuntimeException(
                            "Freshdesk group {$groupId} was not present after synchronization."
                        );
                    }
                });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                "Timed out waiting to synchronize Freshdesk group {$groupId}.",
                previous: $exception
            );
        }
    }

    private function isKnownAndActive(string $groupId): bool
    {
        $group = FreshdeskGroup::query()->whereKey($groupId)->first();

        return $group !== null
            && $group->is_active
            && $group->name !== ''
            && ! str_starts_with($group->name, 'Freshdesk Group ');
    }

    private function extractGroupIds(array $payload): array
    {
        $values = [
            data_get($payload, 'ticket_data.group_id'),
            data_get($payload, 'ticket.group_id'),
            data_get($payload, 'raw_payload.ticket.group_id'),
        ];

        foreach (($payload['changes'] ?? []) as $change) {
            if (is_array($change) && ($change['field'] ?? null) === 'group_id') {
                $values[] = $change['new_value'] ?? null;
            }
        }

        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)))) {
                $ids[] = (string) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
