<?php

namespace Tests\Unit;

use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DurableWebhookSpoolTest extends TestCase
{
    private string $spoolRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spoolRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'v34-spool-test-'.bin2hex(random_bytes(8));
        config()->set('freshdesk_spool.root', $this->spoolRoot);
        config()->set('freshdesk_spool.require_directory_fsync', false);
        config()->set('freshdesk_spool.gc_after_seconds', 0);
    }

    protected function tearDown(): void
    {
        $expectedPrefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'v34-spool-test-';
        $resolvedParent = realpath(dirname($this->spoolRoot));
        if ($resolvedParent && str_starts_with(
            $resolvedParent.DIRECTORY_SEPARATOR.basename($this->spoolRoot),
            $expectedPrefix
        )) {
            File::deleteDirectory($this->spoolRoot);
        }
        parent::tearDown();
    }

    public function test_payload_is_immutable_through_delivery_and_gc_lifecycle(): void
    {
        $spool = app(DurableWebhookSpool::class);
        $payload = [
            'ticket_id' => 18321,
            'event_type' => 'ticket_created',
            'event_timestamp' => '2026-07-30T08:42:30Z',
            'ticket_data' => ['updated_at' => '2026-07-30T08:42:30Z'],
        ];

        $receipt = $spool->accept($payload, 'test-correlation');
        $ready = $spool->findDueReadyFiles(10);
        $this->assertCount(1, $ready);

        $dispatch = $spool->claimForDispatch($ready[0]);
        $processing = $spool->claimForProcessing($dispatch['destination'], $dispatch['token']);
        $envelope = $spool->readEnvelope($processing['destination']);
        $this->assertSame($receipt['receipt_id'], $envelope['receipt_id']);
        $this->assertSame($payload, $envelope['payload']);

        $spool->markCommitted($processing['destination'], $processing['token']);
        $this->assertSame([], $spool->findFiles('processing', 10));
        $this->assertCount(1, $spool->findFiles('committed-gc', 10));
        $this->assertSame(1, $spool->collectGarbage(10));
        $this->assertSame([], $spool->findFiles('committed-gc', 10));
    }

    public function test_expired_delivery_is_recovered_with_rotated_token_and_backoff(): void
    {
        $spool = app(DurableWebhookSpool::class);
        config()->set('freshdesk_spool.enqueued_visibility_seconds', -1);
        $spool->accept([
            'ticket_id' => 1,
            'event_type' => 'ticket_created',
            'event_timestamp' => '2026-07-30T08:42:30Z',
        ], 'recovery-test');
        $ready = $spool->findDueReadyFiles(1);
        $claim = $spool->claimForDispatch($ready[0]);

        $this->assertSame(1, $spool->recoverExpired('enqueued', 10));
        $this->assertSame([], $spool->findFiles('enqueued', 10));
        $this->assertCount(1, $spool->findFiles('ready', 10));
    }

    public function test_concurrent_accepts_can_create_the_same_spool_directories(): void
    {
        $processCount = 8;
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'v34-spool-barrier-'.bin2hex(random_bytes(8));
        $processes = [];

        try {
            for ($index = 1; $index <= $processCount; $index++) {
                $script = sprintf(
                    <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('freshdesk_spool.root', %s);
config()->set('freshdesk_spool.require_directory_fsync', false);
$deadline = microtime(true) + 10;
while (!is_file(%s) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file(%s)) {
    fwrite(STDERR, 'Timed out waiting for concurrency barrier.');
    exit(2);
}
app(\App\Services\Webhooks\DurableWebhookSpool::class)->accept(
    ['ticket_id' => %d, 'event_type' => 'ticket_created'],
    'concurrency-%d'
);
PHP,
                    var_export($this->spoolRoot, true),
                    var_export($barrier, true),
                    var_export($barrier, true),
                    $index,
                    $index
                );
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, '-r', $script],
                    [
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    base_path()
                );

                $this->assertIsResource($process);
                $processes[] = compact('process', 'pipes');
            }

            $this->assertTrue(touch($barrier));

            foreach ($processes as $child) {
                $stdout = stream_get_contents($child['pipes'][1]);
                $stderr = stream_get_contents($child['pipes'][2]);
                fclose($child['pipes'][1]);
                fclose($child['pipes'][2]);

                $this->assertSame(
                    0,
                    proc_close($child['process']),
                    trim($stdout.PHP_EOL.$stderr)
                );
            }

            $this->assertCount(
                $processCount,
                app(DurableWebhookSpool::class)->findDueReadyFiles($processCount + 1)
            );
        } finally {
            @unlink($barrier);
            foreach ($processes as $child) {
                if (is_resource($child['process'])) {
                    proc_terminate($child['process']);
                }
            }
        }
    }
}
