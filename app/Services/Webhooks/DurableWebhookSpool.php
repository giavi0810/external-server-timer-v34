<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Str;
use RuntimeException;

class DurableWebhookSpool
{
    public const STATES = [
        'temporary',
        'ready',
        'enqueued',
        'processing',
        'committed-gc',
        'quarantine',
    ];

    public function accept(array $payload, string $correlationId): array
    {
        $receiptId = (string) Str::uuid();
        $envelope = [
            'schema_version' => 1,
            'receipt_id' => $receiptId,
            'correlation_id' => $correlationId,
            'received_at' => now()->utc()->toIso8601String(),
            'payload' => $payload,
        ];
        $envelope['payload_checksum'] = hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (strlen($json) > config('freshdesk_spool.max_payload_bytes')) {
            throw new RuntimeException('Freshdesk webhook payload exceeds durable spool limit.');
        }

        $temporary = $this->statePath('temporary').DIRECTORY_SEPARATOR.$receiptId.'.tmp';
        $bucket = now()->utc()->format('Y/m/d/H/i');
        $readyDirectory = $this->statePath('ready').DIRECTORY_SEPARATOR.$bucket;
        $ready = $readyDirectory.DIRECTORY_SEPARATOR.$this->fileName($receiptId, 0, time(), 'none');

        $this->ensureDirectory(dirname($temporary));
        $this->ensureDirectory($readyDirectory);
        $this->writeDurably($temporary, $json);
        $this->renameDurably($temporary, $ready);

        return [
            'receipt_id' => $receiptId,
            'correlation_id' => $correlationId,
            'received_at' => $envelope['received_at'],
        ];
    }

    public function findDueReadyFiles(int $limit): array
    {
        return $this->findFiles('ready', $limit, function (array $metadata): bool {
            return $metadata['next_attempt_at'] <= time();
        });
    }

    public function findFiles(string $state, int $limit, ?callable $filter = null): array
    {
        $root = $this->statePath($state);
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
                continue;
            }

            $metadata = $this->parseFileName($file->getFilename());
            if (!$metadata || ($filter && !$filter($metadata, $file->getPathname()))) {
                continue;
            }

            $files[] = $file->getPathname();
            if (count($files) >= $limit) {
                break;
            }
        }

        sort($files, SORT_STRING);
        return $files;
    }

    public function claimForDispatch(string $readyPath): array
    {
        $metadata = $this->requireMetadata($readyPath);
        $token = bin2hex(random_bytes(16));
        $destination = $this->statePath('enqueued').DIRECTORY_SEPARATOR
            .$this->fileName($metadata['receipt_id'], $metadata['attempt'], time(), $token);

        $this->ensureDirectory(dirname($destination));
        $this->renameDurably($readyPath, $destination);

        return compact('destination', 'token') + $metadata;
    }

    public function claimForProcessing(string $enqueuedPath, string $expectedToken): array
    {
        $metadata = $this->requireMetadata($enqueuedPath);
        if (!hash_equals($metadata['token'], $expectedToken)) {
            throw new RuntimeException('Freshdesk spool delivery token is stale.');
        }

        $token = bin2hex(random_bytes(16));
        $destination = $this->statePath('processing').DIRECTORY_SEPARATOR
            .$this->fileName($metadata['receipt_id'], $metadata['attempt'], time(), $token);
        $this->ensureDirectory(dirname($destination));
        $this->renameDurably($enqueuedPath, $destination);

        return compact('destination', 'token') + $metadata;
    }

    public function readEnvelope(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read Freshdesk spool file: {$path}");
        }

        $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach (['receipt_id', 'payload_checksum', 'payload'] as $key) {
            if (!array_key_exists($key, $envelope)) {
                throw new RuntimeException("Freshdesk spool envelope is missing {$key}.");
            }
        }

        $checksum = hash(
            'sha256',
            json_encode($envelope['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        if (!hash_equals($envelope['payload_checksum'], $checksum)) {
            throw new RuntimeException('Freshdesk spool checksum mismatch.');
        }

        return $envelope;
    }

    public function markCommitted(string $processingPath, string $expectedToken): string
    {
        return $this->moveWithToken($processingPath, 'committed-gc', $expectedToken, time(), false);
    }

    public function retry(string $path, string $expectedToken, ?\Throwable $error = null): string
    {
        $metadata = $this->requireMetadata($path);
        if (!hash_equals($metadata['token'], $expectedToken)) {
            throw new RuntimeException('Cannot retry a stale Freshdesk spool lease.');
        }

        $attempt = $metadata['attempt'] + 1;
        if ($attempt >= config('freshdesk_spool.max_attempts')) {
            return $this->moveWithToken($path, 'quarantine', $expectedToken, time(), true, $attempt);
        }

        $backoff = config('freshdesk_spool.backoff');
        $delay = $backoff[min($attempt - 1, count($backoff) - 1)] ?? 600;
        return $this->moveWithToken($path, 'ready', $expectedToken, time() + $delay, true, $attempt);
    }

    public function quarantine(string $path, string $expectedToken): string
    {
        return $this->moveWithToken($path, 'quarantine', $expectedToken, time(), true);
    }

    public function recoverExpired(string $state, int $limit): int
    {
        $leaseSeconds = $state === 'enqueued'
            ? config('freshdesk_spool.enqueued_visibility_seconds')
            : config('freshdesk_spool.processing_lease_seconds');
        $files = $this->findFiles($state, $limit, fn (array $metadata): bool =>
            $metadata['leased_at'] + $leaseSeconds <= time()
        );
        $recovered = 0;

        foreach ($files as $path) {
            $metadata = $this->requireMetadata($path);
            try {
                $this->retry($path, $metadata['token']);
                $recovered++;
            } catch (\Throwable) {
                // A worker may have rotated the token/path after the scan.
            }
        }

        return $recovered;
    }

    public function collectGarbage(int $limit): int
    {
        $cutoff = time() - config('freshdesk_spool.gc_after_seconds');
        $files = $this->findFiles('committed-gc', $limit, fn (array $metadata): bool =>
            $metadata['leased_at'] <= $cutoff
        );
        $deleted = 0;

        foreach ($files as $path) {
            if (@unlink($path)) {
                $this->syncDirectory(dirname($path));
                $this->removeEmptyParents(dirname($path), $this->statePath('committed-gc'));
                $deleted++;
            }
        }

        return $deleted;
    }

    private function moveWithToken(
        string $path,
        string $state,
        string $expectedToken,
        int $timestamp,
        bool $newToken,
        ?int $attempt = null
    ): string {
        $metadata = $this->requireMetadata($path);
        if (!hash_equals($metadata['token'], $expectedToken)) {
            throw new RuntimeException('Freshdesk spool lease token mismatch.');
        }

        $token = $newToken ? bin2hex(random_bytes(16)) : $expectedToken;
        $attempt ??= $metadata['attempt'];
        $destination = $this->statePath($state).DIRECTORY_SEPARATOR
            .$this->fileName($metadata['receipt_id'], $attempt, $timestamp, $token);
        $this->ensureDirectory(dirname($destination));
        $this->renameDurably($path, $destination);
        return $destination;
    }

    private function writeDurably(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if (!$handle) {
            throw new RuntimeException("Unable to create Freshdesk spool file: {$path}");
        }

        try {
            $remaining = $contents;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Unable to write Freshdesk spool file: {$path}");
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException("Unable to fsync Freshdesk spool file: {$path}");
            }
        } finally {
            fclose($handle);
        }
    }

    private function renameDurably(string $source, string $destination): void
    {
        $sourceParent = dirname($source);
        $destinationParent = dirname($destination);
        if (!@rename($source, $destination)) {
            throw new RuntimeException("Unable to atomically move Freshdesk spool file to {$destination}.");
        }
        $this->syncDirectory($sourceParent);
        if ($destinationParent !== $sourceParent) {
            $this->syncDirectory($destinationParent);
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
        if (!is_dir($directory) && !@mkdir($directory, 0770)) {
            clearstatcache(true, $directory);
            if (!is_dir($directory)) {
                throw new RuntimeException("Unable to create Freshdesk spool directory: {$directory}");
            }
        }
        $this->syncDirectory($directory);
        if ($parent !== $directory) {
            $this->syncDirectory($parent);
        }
    }

    private function syncDirectory(string $directory): void
    {
        $binary = config('freshdesk_spool.fsync_dir_binary');
        if (PHP_OS_FAMILY === 'Windows' || !is_executable($binary)) {
            if (config('freshdesk_spool.require_directory_fsync')) {
                throw new RuntimeException("Directory fsync helper is unavailable: {$binary}");
            }
            return;
        }

        $command = escapeshellarg($binary).' '.escapeshellarg($directory);
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException("Unable to fsync directory: {$directory}");
        }
    }

    private function removeEmptyParents(string $directory, string $boundary): void
    {
        while ($directory !== $boundary && str_starts_with($directory, $boundary)) {
            if (!@rmdir($directory)) {
                return;
            }
            $parent = dirname($directory);
            $this->syncDirectory($parent);
            $directory = $parent;
        }
    }

    private function statePath(string $state): string
    {
        if (!in_array($state, self::STATES, true)) {
            throw new RuntimeException("Unknown Freshdesk spool state: {$state}");
        }
        return rtrim(config('freshdesk_spool.root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$state;
    }

    private function fileName(string $receiptId, int $attempt, int $timestamp, string $token): string
    {
        return "{$timestamp}--{$attempt}--{$receiptId}--{$token}.json";
    }

    private function parseFileName(string $name): ?array
    {
        if (!preg_match('/^(\d+)--(\d+)--([0-9a-f-]{36})--([0-9a-f]+|none)\.json$/i', $name, $matches)) {
            return null;
        }

        return [
            'leased_at' => (int) $matches[1],
            'next_attempt_at' => (int) $matches[1],
            'attempt' => (int) $matches[2],
            'receipt_id' => strtolower($matches[3]),
            'token' => strtolower($matches[4]),
        ];
    }

    private function requireMetadata(string $path): array
    {
        return $this->parseFileName(basename($path))
            ?? throw new RuntimeException("Invalid Freshdesk spool filename: {$path}");
    }
}
