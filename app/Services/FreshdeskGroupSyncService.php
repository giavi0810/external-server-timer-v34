<?php

namespace App\Services;

use App\Exceptions\FreshdeskApiRateLimitExceededException;
use App\Exceptions\FreshdeskGroupMissingException;
use App\Exceptions\FreshdeskGroupRefreshFailedException;
use App\Exceptions\FreshdeskGroupRefreshInProgressException;
use App\Models\FreshdeskGroup;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FreshdeskGroupSyncService
{
    private const LOCK_KEY = 'freshdesk-groups:refresh';

    private const LAST_SUCCESSFUL_REFRESH_KEY = 'freshdesk-groups:last-successful-refresh';

    private const MISSING_KEY_PREFIX = 'freshdesk-groups:missing:';

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

        $missingKey = self::MISSING_KEY_PREFIX.$groupId;
        if (Cache::has($missingKey)) {
            throw new FreshdeskGroupMissingException($groupId);
        }

        try {
            Cache::lock(
                self::LOCK_KEY,
                max(1, (int) config('freshdesk.group_sync.lock_seconds', 45))
            )->block(
                max(0, (int) config('freshdesk.group_sync.lock_wait_seconds', 2)),
                function () use ($groupId, $missingKey): void {
                    if ($this->isKnownAndActive($groupId)) {
                        Cache::forget($missingKey);

                        return;
                    }

                    if (Cache::has($missingKey)) {
                        throw new FreshdeskGroupMissingException($groupId);
                    }

                    if (Cache::has(self::LAST_SUCCESSFUL_REFRESH_KEY)) {
                        $this->rememberMissingGroup($missingKey);
                        throw new FreshdeskGroupMissingException($groupId);
                    }

                    Log::notice('Unknown Freshdesk group detected; refreshing group mappings', [
                        'group_id' => $groupId,
                    ]);
                    try {
                        $this->freshdesk->refreshGroupMappings();
                    } catch (FreshdeskApiRateLimitExceededException $exception) {
                        throw $exception;
                    } catch (\Throwable $exception) {
                        throw new FreshdeskGroupRefreshFailedException($groupId, $exception);
                    }

                    Cache::put(
                        self::LAST_SUCCESSFUL_REFRESH_KEY,
                        now()->utc()->toIso8601String(),
                        max(1, (int) config('freshdesk.group_sync.refresh_cooldown_seconds', 1800))
                    );

                    if (! $this->isKnownAndActive($groupId)) {
                        $this->rememberMissingGroup($missingKey);
                        throw new FreshdeskGroupMissingException($groupId);
                    }

                    Cache::forget($missingKey);
                }
            );
        } catch (LockTimeoutException $exception) {
            throw new FreshdeskGroupRefreshInProgressException($groupId);
        }
    }

    private function rememberMissingGroup(string $key): void
    {
        Cache::put(
            $key,
            true,
            max(1, (int) config('freshdesk.group_sync.missing_ttl_seconds', 1800))
        );
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
