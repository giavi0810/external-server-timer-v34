<?php

namespace App\Services\Alerts;

use App\Models\RocketChatDeliveryStatus;
use RuntimeException;

class RocketChatAuditSpool
{
    private const STATES = ['temporary', 'pending', 'ready', 'processing'];

    /**
     * @return array{delivery_id: string, path: string}
     */
    public function begin(string $deliveryId, string $eventCode): array
    {
        if (! config('rocketchat_audit.enabled', true)) {
            return ['delivery_id' => $deliveryId, 'path' => ''];
        }

        if (preg_match('/^[A-Z0-9_]{1,100}$/', $eventCode) !== 1) {
            throw new RuntimeException('Invalid RocketChat audit event code.');
        }

        $envelope = [
            'schema_version' => 1,
            'delivery_id' => $deliveryId,
            'event_code' => $eventCode,
            'status' => RocketChatDeliveryStatus::STATUS_PENDING,
            'http_status' => null,
            'rocketchat_message_id' => null,
            'attempt_count' => 0,
            'attempted_at' => now()->utc()->toIso8601String(),
            'completed_at' => null,
        ];

        $temporary = $this->statePath('temporary').DIRECTORY_SEPARATOR.$deliveryId.'.tmp';
        $pending = $this->statePath('pending').DIRECTORY_SEPARATOR.$deliveryId.'.json';
        $this->ensureDirectories();
        $this->writeDurably($temporary, $envelope);
        $this->renameDurably($temporary, $pending);

        return ['delivery_id' => $deliveryId, 'path' => $pending];
    }

    public function complete(
        string $deliveryId,
        string $status,
        ?int $httpStatus,
        ?string $messageId,
        int $attemptCount
    ): void {
        if (! config('rocketchat_audit.enabled', true)) {
            return;
        }

        if (! in_array($status, [
            RocketChatDeliveryStatus::STATUS_SUCCESS,
            RocketChatDeliveryStatus::STATUS_FAILED,
            RocketChatDeliveryStatus::STATUS_UNKNOWN,
        ], true)) {
            throw new RuntimeException('Invalid RocketChat audit completion status.');
        }

        $pending = $this->statePath('pending').DIRECTORY_SEPARATOR.$deliveryId.'.json';
        $envelope = $this->readEnvelope($pending);
        $envelope['status'] = $status;
        $envelope['http_status'] = $httpStatus;
        $envelope['rocketchat_message_id'] = $messageId;
        $envelope['attempt_count'] = max(0, $attemptCount);
        $envelope['completed_at'] = now()->utc()->toIso8601String();

        $this->publishCompleted($pending, $envelope);
    }

    public function recoverExpiredPending(int $limit): int
    {
        if (! config('rocketchat_audit.enabled', true)) {
            return 0;
        }

        $timeout = max(
            30,
            (int) config('rocketchat_audit.pending_timeout_seconds', 300)
        );
        $cutoff = time() - $timeout;
        $recovered = 0;

        foreach ($this->findFiles('pending', $limit) as $pending) {
            $modifiedAt = filemtime($pending);
            if ($modifiedAt === false || $modifiedAt > $cutoff) {
                continue;
            }

            try {
                $envelope = $this->readEnvelope($pending);
                $envelope['status'] = RocketChatDeliveryStatus::STATUS_UNKNOWN;
                $envelope['completed_at'] = now()->utc()->toIso8601String();
                $this->publishCompleted($pending, $envelope);
                $recovered++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $recovered;
    }

    public function recoverExpiredProcessing(int $limit): int
    {
        if (! config('rocketchat_audit.enabled', true)) {
            return 0;
        }

        $timeout = max(
            30,
            (int) config('rocketchat_audit.processing_timeout_seconds', 300)
        );
        $cutoff = time() - $timeout;
        $recovered = 0;

        foreach ($this->findFiles('processing', $limit) as $processing) {
            $modifiedAt = filemtime($processing);
            if ($modifiedAt === false || $modifiedAt > $cutoff) {
                continue;
            }

            try {
                $envelope = $this->readEnvelope($processing);
                $ready = $this->statePath('ready').DIRECTORY_SEPARATOR
                    .$envelope['delivery_id'].'.json';

                if (is_file($ready)) {
                    if (! @unlink($processing)) {
                        throw new RuntimeException(
                            "Unable to remove duplicate RocketChat audit claim: {$processing}"
                        );
                    }
                    $this->syncDirectory(dirname($processing));
                } else {
                    $this->renameDurably($processing, $ready);
                }
                $recovered++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $recovered;
    }

    /**
     * @return list<string>
     */
    public function findReady(int $limit): array
    {
        return $this->findFiles('ready', $limit);
    }

    /**
     * @return array{path: string, token: string, envelope: array<string, mixed>}
     */
    public function claimReady(string $readyPath): array
    {
        $envelope = $this->readEnvelope($readyPath);
        $token = bin2hex(random_bytes(16));
        $processing = $this->statePath('processing').DIRECTORY_SEPARATOR
            .$token.'--'.$envelope['delivery_id'].'.json';
        $this->renameDurably($readyPath, $processing);

        return [
            'path' => $processing,
            'token' => $token,
            'envelope' => $envelope,
        ];
    }

    public function acknowledge(string $processingPath, string $expectedToken): void
    {
        $this->assertProcessingToken($processingPath, $expectedToken);
        if (! @unlink($processingPath)) {
            throw new RuntimeException("Unable to delete RocketChat audit file: {$processingPath}");
        }
        $this->syncDirectory(dirname($processingPath));
    }

    public function release(string $processingPath, string $expectedToken): void
    {
        $this->assertProcessingToken($processingPath, $expectedToken);
        $envelope = $this->readEnvelope($processingPath);
        $ready = $this->statePath('ready').DIRECTORY_SEPARATOR
            .$envelope['delivery_id'].'.json';

        if (is_file($ready)) {
            if (! @unlink($processingPath)) {
                throw new RuntimeException(
                    "Unable to remove duplicate RocketChat audit claim: {$processingPath}"
                );
            }
            $this->syncDirectory(dirname($processingPath));

            return;
        }

        $this->renameDurably($processingPath, $ready);
    }

    /**
     * @return array<string, mixed>
     */
    public function readEnvelope(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read RocketChat audit file: {$path}");
        }

        $envelope = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        foreach ([
            'delivery_id',
            'event_code',
            'status',
            'attempt_count',
            'attempted_at',
        ] as $field) {
            if (! array_key_exists($field, $envelope)) {
                throw new RuntimeException("RocketChat audit file is missing {$field}.");
            }
        }

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function publishCompleted(string $pending, array $envelope): void
    {
        $deliveryId = (string) $envelope['delivery_id'];
        $temporary = $this->statePath('temporary').DIRECTORY_SEPARATOR
            .$deliveryId.'.'.bin2hex(random_bytes(8)).'.result.tmp';
        $ready = $this->statePath('ready').DIRECTORY_SEPARATOR.$deliveryId.'.json';

        $this->writeDurably($temporary, $envelope);
        if (is_file($ready)) {
            @unlink($temporary);
        } else {
            $this->renameDurably($temporary, $ready);
        }

        if (is_file($pending)) {
            if (! @unlink($pending)) {
                throw new RuntimeException(
                    "Unable to remove pending RocketChat audit file: {$pending}"
                );
            }
            $this->syncDirectory(dirname($pending));
        }
    }

    /**
     * @return list<string>
     */
    private function findFiles(string $state, int $limit): array
    {
        $directory = $this->statePath($state);
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files, SORT_STRING);

        return array_slice($files, 0, max(1, $limit));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function writeDurably(string $path, array $envelope): void
    {
        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create RocketChat audit file: {$path}");
        }

        try {
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Unable to write RocketChat audit file: {$path}");
                }
                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new RuntimeException("Unable to flush RocketChat audit file: {$path}");
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException("Unable to fsync RocketChat audit file: {$path}");
            }
        } finally {
            fclose($handle);
        }
    }

    private function ensureDirectories(): void
    {
        foreach (self::STATES as $state) {
            $this->ensureDirectory($this->statePath($state));
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $parent = dirname($directory);
        if ($parent !== $directory) {
            $this->ensureDirectory($parent);
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0770)) {
            clearstatcache(true, $directory);
            if (! is_dir($directory)) {
                throw new RuntimeException(
                    "Unable to create RocketChat audit directory: {$directory}"
                );
            }
        }

        $this->syncDirectory($directory);
        if ($parent !== $directory) {
            $this->syncDirectory($parent);
        }
    }

    private function renameDurably(string $source, string $destination): void
    {
        $sourceParent = dirname($source);
        $destinationParent = dirname($destination);
        if (! @rename($source, $destination)) {
            throw new RuntimeException(
                "Unable to atomically move RocketChat audit file to {$destination}"
            );
        }

        $this->syncDirectory($sourceParent);
        if ($sourceParent !== $destinationParent) {
            $this->syncDirectory($destinationParent);
        }
    }

    private function syncDirectory(string $directory): void
    {
        $binary = (string) config('rocketchat_audit.fsync_dir_binary');
        if (PHP_OS_FAMILY === 'Windows' || ! is_executable($binary)) {
            if (config('rocketchat_audit.require_directory_fsync')) {
                throw new RuntimeException("Directory fsync helper is unavailable: {$binary}");
            }

            return;
        }

        exec(escapeshellarg($binary).' '.escapeshellarg($directory), $output, $status);
        if ($status !== 0) {
            throw new RuntimeException("Unable to fsync directory: {$directory}");
        }
    }

    private function assertProcessingToken(string $path, string $expectedToken): void
    {
        $name = basename($path);
        if (! str_starts_with($name, $expectedToken.'--')) {
            throw new RuntimeException('RocketChat audit claim token is stale.');
        }
    }

    private function statePath(string $state): string
    {
        if (! in_array($state, self::STATES, true)) {
            throw new RuntimeException("Unknown RocketChat audit state: {$state}");
        }

        return rtrim(
            (string) config('rocketchat_audit.root'),
            DIRECTORY_SEPARATOR
        ).DIRECTORY_SEPARATOR.$state;
    }
}
