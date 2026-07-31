<?php

namespace App\Jobs;

use App\Http\Controllers\WebhookController;
use App\Http\Requests\FreshdeskWebhookRequest;
use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PersistFreshdeskWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 105;
    public int $tries = 1;

    public function __construct(
        public readonly string $spoolPath,
        public readonly string $deliveryToken
    ) {
        $this->onQueue(config('freshdesk_spool.queue'));
    }

    public function handle(DurableWebhookSpool $spool, WebhookController $controller): void
    {
        try {
            $claim = $spool->claimForProcessing($this->spoolPath, $this->deliveryToken);
        } catch (\Throwable $exception) {
            Log::notice('Freshdesk ingest delivery is stale or already claimed', [
                'spool_path' => $this->spoolPath,
                'reason' => $exception->getMessage(),
            ]);
            return;
        }

        $processingPath = $claim['destination'];
        $processingToken = $claim['token'];

        try {
            $envelope = $spool->readEnvelope($processingPath);
            if (!hash_equals($claim['receipt_id'], (string) $envelope['receipt_id'])) {
                throw new RuntimeException('Freshdesk spool receipt ID mismatch.');
            }

            $committedReceipt = DB::table('freshdesk_webhook_receipts')
                ->where('receipt_id', $envelope['receipt_id'])
                ->exists();
            if ($committedReceipt) {
                $spool->markCommitted($processingPath, $processingToken);
                return;
            }

            $request = FreshdeskWebhookRequest::create(
                '/api/webhooks/freshdesk',
                'POST',
                $envelope['payload']
            );
            $request->headers->set('Accept', 'application/json');
            $request->headers->set('X-Correlation-ID', $envelope['correlation_id'] ?? $envelope['receipt_id']);
            $request->setContainer(app());
            $request->setRedirector(app('redirect'));
            $request->validateResolved();

            DB::beginTransaction();
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [
                        (int) ($envelope['payload']['ticket_id'] ?? 0),
                    ]);
                }
                $response = $controller->processPersistedFreshdeskTicketEvent(
                    $request,
                    (string) $envelope['receipt_id']
                );

                if ($response->getStatusCode() >= 400) {
                    DB::rollBack();
                } else {
                    DB::table('freshdesk_webhook_receipts')->insertOrIgnore([
                        'receipt_id' => $envelope['receipt_id'],
                        'ticket_id' => $envelope['payload']['ticket_id'] ?? null,
                        'payload_checksum' => $envelope['payload_checksum'],
                        'received_at' => $envelope['received_at'],
                        'committed_at' => now(),
                    ]);
                    DB::commit();
                }
            } catch (\Throwable $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                throw $exception;
            }

            if ($response->getStatusCode() >= 500) {
                throw new RuntimeException("Persisted webhook processing returned HTTP {$response->getStatusCode()}.");
            }

            if ($response->getStatusCode() >= 400) {
                $spool->quarantine($processingPath, $processingToken);
                Log::error('Freshdesk spool payload quarantined after permanent validation failure', [
                    'receipt_id' => $envelope['receipt_id'],
                    'http_status' => $response->getStatusCode(),
                ]);
                return;
            }

            $spool->markCommitted($processingPath, $processingToken);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $spool->quarantine($processingPath, $processingToken);
            Log::error('Freshdesk spool payload quarantined after request validation failure', [
                'receipt_id' => $claim['receipt_id'],
                'http_status' => $exception->getResponse()->getStatusCode(),
            ]);
        } catch (\Throwable $exception) {
            try {
                $spool->retry($processingPath, $processingToken, $exception);
            } catch (\Throwable $recoveryException) {
                Log::critical('Freshdesk spool file could not be returned to ready state', [
                    'receipt_id' => $claim['receipt_id'],
                    'error' => $exception->getMessage(),
                    'recovery_error' => $recoveryException->getMessage(),
                ]);
                throw $recoveryException;
            }

            Log::warning('Freshdesk webhook persistence deferred', [
                'receipt_id' => $claim['receipt_id'],
                'attempt' => $claim['attempt'] + 1,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
