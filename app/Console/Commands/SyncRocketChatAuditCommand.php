<?php

namespace App\Console\Commands;

use App\Models\RocketChatDeliveryStatus;
use App\Services\Alerts\RocketChatAuditSpool;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRocketChatAuditCommand extends Command
{
    protected $signature = 'rocketchat-audit:sync {--limit= : Maximum files per run}';

    protected $description = 'Synchronize durable RocketChat delivery audit files to PostgreSQL';

    public function handle(RocketChatAuditSpool $spool): int
    {
        $limit = max(
            1,
            min(1000, (int) ($this->option('limit') ?: config('rocketchat_audit.sync_batch', 100)))
        );

        $spool->recoverExpiredProcessing($limit);
        $spool->recoverExpiredPending($limit);
        $synced = 0;

        foreach ($spool->findReady($limit) as $readyPath) {
            try {
                $claim = $spool->claimReady($readyPath);
            } catch (Throwable $exception) {
                Log::warning('Unable to claim RocketChat audit file', [
                    'file' => basename($readyPath),
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            try {
                $envelope = $this->validatedEnvelope($claim['envelope']);

                DB::transaction(function () use ($envelope): void {
                    RocketChatDeliveryStatus::query()->updateOrCreate(
                        ['delivery_id' => $envelope['delivery_id']],
                        [
                            'event_code' => $envelope['event_code'],
                            'status' => $envelope['status'],
                            'http_status' => $envelope['http_status'],
                            'rocketchat_message_id' => $envelope['rocketchat_message_id'],
                            'attempt_count' => $envelope['attempt_count'],
                            'attempted_at' => $envelope['attempted_at'],
                            'completed_at' => $envelope['completed_at'],
                        ]
                    );
                }, 3);

                $spool->acknowledge($claim['path'], $claim['token']);
                $synced++;
            } catch (Throwable $exception) {
                try {
                    $spool->release($claim['path'], $claim['token']);
                } catch (Throwable $releaseException) {
                    Log::error('Unable to release RocketChat audit claim', [
                        'file' => basename($claim['path']),
                        'error' => $releaseException->getMessage(),
                    ]);
                }

                Log::warning('RocketChat audit synchronization stopped', [
                    'file' => basename($claim['path']),
                    'error' => $exception->getMessage(),
                ]);

                break;
            }
        }

        $this->info("Synchronized {$synced} RocketChat delivery audit record(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{
     *     delivery_id: string,
     *     event_code: string,
     *     status: string,
     *     http_status: int|null,
     *     rocketchat_message_id: string|null,
     *     attempt_count: int,
     *     attempted_at: CarbonImmutable,
     *     completed_at: CarbonImmutable|null
     * }
     */
    private function validatedEnvelope(array $envelope): array
    {
        $status = (string) ($envelope['status'] ?? '');
        if (! in_array($status, [
            RocketChatDeliveryStatus::STATUS_SUCCESS,
            RocketChatDeliveryStatus::STATUS_FAILED,
            RocketChatDeliveryStatus::STATUS_UNKNOWN,
        ], true)) {
            throw new \RuntimeException('Invalid completed RocketChat audit status.');
        }

        $eventCode = (string) ($envelope['event_code'] ?? '');
        if (preg_match('/^[A-Z0-9_]{1,100}$/', $eventCode) !== 1) {
            throw new \RuntimeException('Invalid RocketChat audit event code.');
        }

        $httpStatus = $envelope['http_status'] ?? null;
        if ($httpStatus !== null && (! is_int($httpStatus) || $httpStatus < 100 || $httpStatus > 599)) {
            throw new \RuntimeException('Invalid RocketChat audit HTTP status.');
        }

        return [
            'delivery_id' => (string) $envelope['delivery_id'],
            'event_code' => $eventCode,
            'status' => $status,
            'http_status' => $httpStatus,
            'rocketchat_message_id' => isset($envelope['rocketchat_message_id'])
                ? (string) $envelope['rocketchat_message_id']
                : null,
            'attempt_count' => max(0, (int) ($envelope['attempt_count'] ?? 0)),
            'attempted_at' => CarbonImmutable::parse((string) $envelope['attempted_at'])->utc(),
            'completed_at' => isset($envelope['completed_at'])
                ? CarbonImmutable::parse((string) $envelope['completed_at'])->utc()
                : null,
        ];
    }
}
