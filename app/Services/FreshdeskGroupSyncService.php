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
        $groups = [];
        foreach ($payloads as $payload) {
            if (is_array($payload)) {
                foreach ($this->extractGroups($payload) as $groupId => $groupName) {
                    if ($groupName !== null || ! array_key_exists($groupId, $groups)) {
                        $groups[$groupId] = $groupName;
                    }
                }
            }
        }

        foreach ($groups as $groupId => $groupName) {
            $this->ensureGroupKnown($groupId, $groupName);
        }
    }

    public function ensureGroupKnown(string $groupId, ?string $expectedName = null): void
    {
        if ($this->isKnownAndActive($groupId, $expectedName)) {
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
                function () use ($groupId, $expectedName, $missingKey): void {
                    if ($this->isKnownAndActive($groupId, $expectedName)) {
                        Cache::forget($missingKey);

                        return;
                    }

                    if (Cache::has($missingKey)) {
                        throw new FreshdeskGroupMissingException($groupId);
                    }

                    $groupExists = $this->isKnownAndActive($groupId);
                    if (! $groupExists && Cache::has(self::LAST_SUCCESSFUL_REFRESH_KEY)) {
                        $this->rememberMissingGroup($missingKey);
                        throw new FreshdeskGroupMissingException($groupId);
                    }

                    Log::notice($groupExists
                        ? 'Freshdesk group name changed; refreshing group mappings'
                        : 'Unknown Freshdesk group detected; refreshing group mappings', [
                        'group_id' => $groupId,
                        'expected_name' => $expectedName,
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

                    if (! $this->isKnownAndActive($groupId, $expectedName)) {
                        Log::warning('Freshdesk webhook group name differs from the refreshed API mapping', [
                            'group_id' => $groupId,
                            'webhook_name' => $expectedName,
                            'api_name' => FreshdeskGroup::query()->whereKey($groupId)->value('name'),
                        ]);
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

    private function isKnownAndActive(string $groupId, ?string $expectedName = null): bool
    {
        $group = FreshdeskGroup::query()->whereKey($groupId)->first();

        $isKnownAndActive = $group !== null
            && $group->is_active
            && $group->name !== ''
            && ! str_starts_with($group->name, 'Freshdesk Group ');

        if (! $isKnownAndActive || $expectedName === null) {
            return $isKnownAndActive;
        }

        return trim($group->name) === $expectedName;
    }

    /**
     * @return array<string, string|null>
     */
    private function extractGroups(array $payload): array
    {
        $groups = [];
        $ticketPaths = [
            'ticket_data',
            'ticket',
            'raw_payload.ticket',
        ];

        foreach ($ticketPaths as $ticketPath) {
            $groupId = $this->normalizeGroupId(data_get($payload, "{$ticketPath}.group_id"));
            if ($groupId === null) {
                continue;
            }

            $groupName = $this->normalizeGroupName(data_get($payload, "{$ticketPath}.group_name"));
            if ($groupName !== null || ! array_key_exists($groupId, $groups)) {
                $groups[$groupId] = $groupName;
            }
        }

        $changedGroupId = null;
        $changedGroupName = null;
        foreach (($payload['changes'] ?? []) as $change) {
            if (! is_array($change)) {
                continue;
            }

            if (($change['field'] ?? null) === 'group_id') {
                $changedGroupId = $this->normalizeGroupId($change['new_value'] ?? null);
            }

            if (($change['field'] ?? null) === 'group_name') {
                $changedGroupName = $this->normalizeGroupName($change['new_value'] ?? null);
            }
        }

        if ($changedGroupId !== null
            && ($changedGroupName !== null || ! array_key_exists($changedGroupId, $groups))) {
            $groups[$changedGroupId] = $changedGroupName;
        }

        return $groups;
    }

    private function normalizeGroupId(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)))) {
            return trim((string) $value);
        }

        return null;
    }

    private function normalizeGroupName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        return $name === '' ? null : $name;
    }
}
