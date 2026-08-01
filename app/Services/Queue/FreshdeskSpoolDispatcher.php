<?php

namespace App\Services\Queue;

use App\Jobs\PersistFreshdeskWebhookJob;
use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Support\Facades\Log;

class FreshdeskSpoolDispatcher
{
    public function __construct(private readonly DurableWebhookSpool $spool) {}

    public function dispatch(int $limit): DispatchResult
    {
        $dispatched = 0;
        $failures = 0;

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
