<?php

namespace App\Services\Queue;

use App\Jobs\PersistFreshdeskWebhookJob;
use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class FreshdeskSpoolDispatcher
{
    public function __construct(private readonly DurableWebhookSpool $spool) {}

    public function dispatch(int $limit): DispatchResult
    {
        $dispatched = 0;
        $failures = 0;
        $queue = (string) config('freshdesk_spool.queue');
        $maximumDepth = max(0, (int) config('freshdesk_spool.max_queue_depth', 100));

        if ($maximumDepth > 0) {
            try {
                $availableCapacity = max(0, $maximumDepth - Queue::size($queue));
            } catch (\Throwable $exception) {
                Log::warning('Unable to inspect Freshdesk ingest queue depth', [
                    'queue' => $queue,
                    'reason' => $exception->getMessage(),
                ]);

                return new DispatchResult(0, 1);
            }

            if ($availableCapacity === 0) {
                return new DispatchResult;
            }

            $limit = min($limit, $availableCapacity);
        }

        foreach ($this->spool->findDueReadyFiles(max(1, $limit)) as $readyPath) {
            try {
                $claim = $this->spool->claimForDispatch($readyPath);
                try {
                    PersistFreshdeskWebhookJob::dispatch($claim['destination'], $claim['token']);
                    $dispatched++;
                } catch (\Throwable $exception) {
                    $this->spool->retry($claim['destination'], $claim['token'], $exception);
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                $failures++;
                Log::warning('Unable to dispatch Freshdesk spool receipt', [
                    'path' => $readyPath,
                    'reason' => $exception->getMessage(),
                ]);

                // Redis/network failures are normally systemic. Stop this batch so one
                // cycle does not repeat the same failing connection for every file.
                break;
            }
        }

        return new DispatchResult($dispatched, $failures);
    }
}
