<?php

namespace App\Services\Alerts;

use Illuminate\Support\Str;
use RuntimeException;

class RocketChatAlertStateStore
{
    /**
     * @return array{token: string}|null
     */
    public function claimNotification(string $key, int $dedupSeconds, int $globalRateSeconds): ?array
    {
        return $this->withLockedState(function (array &$state) use (
            $key,
            $dedupSeconds,
            $globalRateSeconds
        ): ?array {
            $now = now()->timestamp;
            $claimSeconds = $this->claimSeconds();
            $notification = $state['notifications'][$key] ?? [];

            if (($notification['last_sent_at'] ?? 0) + $dedupSeconds > $now
                || ($notification['claim_expires_at'] ?? 0) > $now
                || ($state['global']['last_sent_at'] ?? 0) + $globalRateSeconds > $now
                || ($state['global']['claim_expires_at'] ?? 0) > $now
            ) {
                return null;
            }

            $token = (string) Str::uuid();
            $expiresAt = $now + $claimSeconds;
            $state['notifications'][$key] = $notification + [];
            $state['notifications'][$key]['claim_token'] = $token;
            $state['notifications'][$key]['claim_expires_at'] = $expiresAt;
            $state['global']['claim_token'] = $token;
            $state['global']['claim_expires_at'] = $expiresAt;

            return ['token' => $token];
        });
    }

    public function completeNotification(string $key, string $token): void
    {
        $this->withLockedState(function (array &$state) use ($key, $token): void {
            if (($state['notifications'][$key]['claim_token'] ?? null) !== $token) {
                return;
            }

            $now = now()->timestamp;
            $state['notifications'][$key]['last_sent_at'] = $now;
            unset(
                $state['notifications'][$key]['claim_token'],
                $state['notifications'][$key]['claim_expires_at']
            );

            if (($state['global']['claim_token'] ?? null) === $token) {
                $state['global']['last_sent_at'] = $now;
                unset($state['global']['claim_token'], $state['global']['claim_expires_at']);
            }
        });
    }

    public function abandonNotification(string $key, string $token): void
    {
        $this->withLockedState(function (array &$state) use ($key, $token): void {
            if (($state['notifications'][$key]['claim_token'] ?? null) === $token) {
                unset(
                    $state['notifications'][$key]['claim_token'],
                    $state['notifications'][$key]['claim_expires_at']
                );
            }

            if (($state['global']['claim_token'] ?? null) === $token) {
                unset($state['global']['claim_token'], $state['global']['claim_expires_at']);
            }
        });
    }

    /**
     * @return array{
     *     should_send: bool,
     *     token: string|null,
     *     first_detected_at: int,
     *     last_detected_at: int,
     *     occurrence_count: int,
     *     reminder: bool
     * }
     */
    public function claimRedisDown(
        string $fingerprint,
        int $reminderSeconds,
        int $globalRateSeconds
    ): array {
        return $this->withLockedState(function (array &$state) use (
            $fingerprint,
            $reminderSeconds,
            $globalRateSeconds
        ): array {
            $now = now()->timestamp;
            $incident = $state['redis_incidents'][$fingerprint] ?? [];
            $newIncident = ($incident['status'] ?? null) !== 'down';

            if ($newIncident) {
                $incident = [
                    'status' => 'down',
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'occurrence_count' => 0,
                    'last_down_alert_at' => null,
                    'down_alert_sent_at' => null,
                ];
            }

            if (($incident['claim_kind'] ?? null) === 'recovered') {
                $staleRecoveryToken = $incident['claim_token'] ?? null;
                $this->clearIncidentClaim($incident);
                if ($staleRecoveryToken !== null
                    && ($state['global']['claim_token'] ?? null) === $staleRecoveryToken
                ) {
                    unset($state['global']['claim_token'], $state['global']['claim_expires_at']);
                }
            }

            $incident['status'] = 'down';
            $incident['last_detected_at'] = $now;
            $incident['occurrence_count'] = (int) ($incident['occurrence_count'] ?? 0) + 1;

            $lastAlertAt = (int) ($incident['last_down_alert_at'] ?? 0);
            $due = $lastAlertAt === 0 || $lastAlertAt + $reminderSeconds <= $now;
            $claimAvailable = ($incident['claim_expires_at'] ?? 0) <= $now;
            $globalAvailable = ($state['global']['last_sent_at'] ?? 0) + $globalRateSeconds <= $now
                && ($state['global']['claim_expires_at'] ?? 0) <= $now;
            $token = null;

            if ($due && $claimAvailable && $globalAvailable) {
                $token = (string) Str::uuid();
                $expiresAt = $now + $this->claimSeconds();
                $incident['claim_kind'] = 'down';
                $incident['claim_token'] = $token;
                $incident['claim_expires_at'] = $expiresAt;
                $state['global']['claim_token'] = $token;
                $state['global']['claim_expires_at'] = $expiresAt;
            }

            $state['redis_incidents'][$fingerprint] = $incident;

            return [
                'should_send' => $token !== null,
                'token' => $token,
                'first_detected_at' => (int) $incident['first_detected_at'],
                'last_detected_at' => (int) $incident['last_detected_at'],
                'occurrence_count' => (int) $incident['occurrence_count'],
                'reminder' => ! $newIncident,
            ];
        });
    }

    public function completeRedisDown(string $fingerprint, string $token): void
    {
        $this->withLockedState(function (array &$state) use ($fingerprint, $token): void {
            $incident = $state['redis_incidents'][$fingerprint] ?? null;
            if (! is_array($incident)
                || ($incident['claim_kind'] ?? null) !== 'down'
                || ($incident['claim_token'] ?? null) !== $token
            ) {
                return;
            }

            $now = now()->timestamp;
            $incident['last_down_alert_at'] = $now;
            $incident['down_alert_sent_at'] ??= $now;
            $this->clearIncidentClaim($incident);
            $state['redis_incidents'][$fingerprint] = $incident;
            $this->completeGlobalClaim($state, $token, $now);
        });
    }

    /**
     * @return array{
     *     should_send: bool,
     *     token: string|null,
     *     first_detected_at: int|null,
     *     recovered_at: int,
     *     occurrence_count: int,
     *     duration_seconds: int
     * }
     */
    public function claimRedisRecovered(string $fingerprint, int $globalRateSeconds): array
    {
        return $this->withLockedState(function (array &$state) use (
            $fingerprint,
            $globalRateSeconds
        ): array {
            $now = now()->timestamp;
            $incident = $state['redis_incidents'][$fingerprint] ?? null;
            $empty = [
                'should_send' => false,
                'token' => null,
                'first_detected_at' => null,
                'recovered_at' => $now,
                'occurrence_count' => 0,
                'duration_seconds' => 0,
            ];

            if (! is_array($incident) || ($incident['status'] ?? null) !== 'down') {
                return $empty;
            }

            if (($incident['claim_expires_at'] ?? 0) > $now) {
                return $empty;
            }

            if (($incident['down_alert_sent_at'] ?? null) === null) {
                $incident['status'] = 'up';
                $incident['recovered_at'] = $now;
                $this->clearIncidentClaim($incident);
                $state['redis_incidents'][$fingerprint] = $incident;

                return $empty;
            }

            $claimAvailable = ($incident['claim_expires_at'] ?? 0) <= $now;
            $globalAvailable = ($state['global']['last_sent_at'] ?? 0) + $globalRateSeconds <= $now
                && ($state['global']['claim_expires_at'] ?? 0) <= $now;
            $token = null;

            if ($claimAvailable && $globalAvailable) {
                $token = (string) Str::uuid();
                $expiresAt = $now + $this->claimSeconds();
                $incident['claim_kind'] = 'recovered';
                $incident['claim_token'] = $token;
                $incident['claim_expires_at'] = $expiresAt;
                $state['global']['claim_token'] = $token;
                $state['global']['claim_expires_at'] = $expiresAt;
                $state['redis_incidents'][$fingerprint] = $incident;
            }

            $firstDetectedAt = (int) ($incident['first_detected_at'] ?? $now);

            return [
                'should_send' => $token !== null,
                'token' => $token,
                'first_detected_at' => $firstDetectedAt,
                'recovered_at' => $now,
                'occurrence_count' => (int) ($incident['occurrence_count'] ?? 0),
                'duration_seconds' => max(0, $now - $firstDetectedAt),
            ];
        });
    }

    public function completeRedisRecovered(string $fingerprint, string $token): void
    {
        $this->withLockedState(function (array &$state) use ($fingerprint, $token): void {
            $incident = $state['redis_incidents'][$fingerprint] ?? null;
            if (! is_array($incident)
                || ($incident['claim_kind'] ?? null) !== 'recovered'
                || ($incident['claim_token'] ?? null) !== $token
            ) {
                return;
            }

            $now = now()->timestamp;
            $incident['status'] = 'up';
            $incident['recovered_at'] = $now;
            $incident['recovery_alert_sent_at'] = $now;
            $this->clearIncidentClaim($incident);
            $state['redis_incidents'][$fingerprint] = $incident;
            $this->completeGlobalClaim($state, $token, $now);
        });
    }

    public function abandonRedisClaim(string $fingerprint, string $token): void
    {
        $this->withLockedState(function (array &$state) use ($fingerprint, $token): void {
            $incident = $state['redis_incidents'][$fingerprint] ?? null;
            if (is_array($incident) && ($incident['claim_token'] ?? null) === $token) {
                $this->clearIncidentClaim($incident);
                $state['redis_incidents'][$fingerprint] = $incident;
            }

            if (($state['global']['claim_token'] ?? null) === $token) {
                unset($state['global']['claim_token'], $state['global']['claim_expires_at']);
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(array<string, mixed>&): T  $callback
     * @return T
     */
    private function withLockedState(callable $callback): mixed
    {
        $statePath = $this->statePath();
        $directory = dirname($statePath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create RocketChat alert state directory: {$directory}");
        }

        $lock = fopen($statePath.'.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException("Unable to open RocketChat alert state lock: {$statePath}.lock");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire RocketChat alert state lock.');
            }

            $state = $this->readState($statePath);
            $this->pruneExpiredState($state);
            $result = $callback($state);
            $this->writeState($statePath, $state);
            flock($lock, LOCK_UN);

            return $result;
        } finally {
            fclose($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(string $statePath): array
    {
        if (! is_file($statePath)) {
            return ['version' => 1, 'global' => [], 'notifications' => [], 'redis_incidents' => []];
        }

        $json = file_get_contents($statePath);
        $state = $json !== false ? json_decode($json, true) : null;

        return is_array($state)
            ? $state + ['version' => 1, 'global' => [], 'notifications' => [], 'redis_incidents' => []]
            : ['version' => 1, 'global' => [], 'notifications' => [], 'redis_incidents' => []];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(string $statePath, array $state): void
    {
        $json = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $temporary = $statePath.'.'.bin2hex(random_bytes(8)).'.tmp';

        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write RocketChat alert state: {$temporary}");
        }

        if (! @rename($temporary, $statePath)) {
            if (file_put_contents($statePath, $json, LOCK_EX) === false) {
                @unlink($temporary);
                throw new RuntimeException("Unable to publish RocketChat alert state: {$statePath}");
            }

            @unlink($temporary);
        }
    }

    /**
     * @param  array<string, mixed>  $incident
     */
    private function clearIncidentClaim(array &$incident): void
    {
        unset(
            $incident['claim_kind'],
            $incident['claim_token'],
            $incident['claim_expires_at']
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function completeGlobalClaim(array &$state, string $token, int $now): void
    {
        if (($state['global']['claim_token'] ?? null) !== $token) {
            return;
        }

        $state['global']['last_sent_at'] = $now;
        unset($state['global']['claim_token'], $state['global']['claim_expires_at']);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function pruneExpiredState(array &$state): void
    {
        $now = now()->timestamp;
        $retentionSeconds = max(
            3600,
            (int) config('services.rocketchat.alert_state_retention_seconds', 604800)
        );

        foreach (($state['notifications'] ?? []) as $key => $notification) {
            if (! is_array($notification)) {
                unset($state['notifications'][$key]);

                continue;
            }

            $lastActivityAt = max(
                (int) ($notification['last_sent_at'] ?? 0),
                (int) ($notification['claim_expires_at'] ?? 0)
            );
            if ($lastActivityAt > 0 && $lastActivityAt + $retentionSeconds < $now) {
                unset($state['notifications'][$key]);
            }
        }

        foreach (($state['redis_incidents'] ?? []) as $key => $incident) {
            if (! is_array($incident)) {
                unset($state['redis_incidents'][$key]);

                continue;
            }

            $lastActivityAt = max(
                (int) ($incident['last_detected_at'] ?? 0),
                (int) ($incident['recovered_at'] ?? 0),
                (int) ($incident['claim_expires_at'] ?? 0)
            );
            if (($incident['status'] ?? null) === 'up'
                && $lastActivityAt > 0
                && $lastActivityAt + $retentionSeconds < $now
            ) {
                unset($state['redis_incidents'][$key]);
            }
        }
    }

    private function claimSeconds(): int
    {
        return max(5, (int) config('services.rocketchat.alert_claim_seconds', 30));
    }

    private function statePath(): string
    {
        return (string) config(
            'services.rocketchat.alert_state_path',
            storage_path('framework/alerts/rocketchat-state.json')
        );
    }
}
