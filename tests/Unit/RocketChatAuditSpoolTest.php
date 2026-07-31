<?php

namespace Tests\Unit;

use App\Models\RocketChatDeliveryStatus;
use App\Services\Alerts\RocketChatAuditSpool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class RocketChatAuditSpoolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'v34-rocketchat-audit-test-'.bin2hex(random_bytes(8));
        config([
            'rocketchat_audit.enabled' => true,
            'rocketchat_audit.root' => $this->root,
            'rocketchat_audit.pending_timeout_seconds' => 30,
            'rocketchat_audit.processing_timeout_seconds' => 30,
            'rocketchat_audit.require_directory_fsync' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_persists_only_approved_metadata_and_completes_delivery(): void
    {
        $spool = app(RocketChatAuditSpool::class);
        $deliveryId = (string) Str::uuid();

        $pending = $spool->begin(
            $deliveryId,
            RocketChatDeliveryStatus::EVENT_REDIS_DOWN
        );
        $pendingEnvelope = $spool->readEnvelope($pending['path']);

        $this->assertSame([
            'schema_version',
            'delivery_id',
            'event_code',
            'status',
            'http_status',
            'rocketchat_message_id',
            'attempt_count',
            'attempted_at',
            'completed_at',
        ], array_keys($pendingEnvelope));
        $this->assertArrayNotHasKey('text', $pendingEnvelope);
        $this->assertArrayNotHasKey('exception', $pendingEnvelope);

        $spool->complete(
            $deliveryId,
            RocketChatDeliveryStatus::STATUS_SUCCESS,
            200,
            'message-123',
            1
        );

        $ready = $spool->findReady(10);
        $this->assertCount(1, $ready);
        $completed = $spool->readEnvelope($ready[0]);
        $this->assertSame(RocketChatDeliveryStatus::STATUS_SUCCESS, $completed['status']);
        $this->assertSame(200, $completed['http_status']);
        $this->assertSame('message-123', $completed['rocketchat_message_id']);
        $this->assertFileDoesNotExist($pending['path']);
    }

    public function test_it_recovers_expired_pending_and_processing_files(): void
    {
        $spool = app(RocketChatAuditSpool::class);
        $deliveryId = (string) Str::uuid();
        $pending = $spool->begin(
            $deliveryId,
            RocketChatDeliveryStatus::EVENT_SYSTEM_ERROR
        );
        touch($pending['path'], time() - 31);

        $this->assertSame(1, $spool->recoverExpiredPending(10));
        $ready = $spool->findReady(10);
        $this->assertSame(
            RocketChatDeliveryStatus::STATUS_UNKNOWN,
            $spool->readEnvelope($ready[0])['status']
        );

        $claim = $spool->claimReady($ready[0]);
        touch($claim['path'], time() - 31);

        $this->assertSame(1, $spool->recoverExpiredProcessing(10));
        $this->assertCount(1, $spool->findReady(10));
        $this->assertFileDoesNotExist($claim['path']);
    }

    public function test_claim_release_and_acknowledge_are_token_protected(): void
    {
        $spool = app(RocketChatAuditSpool::class);
        $deliveryId = (string) Str::uuid();
        $spool->begin($deliveryId, RocketChatDeliveryStatus::EVENT_SYSTEM_ERROR);
        $spool->complete(
            $deliveryId,
            RocketChatDeliveryStatus::STATUS_FAILED,
            503,
            null,
            2
        );

        $claim = $spool->claimReady($spool->findReady(1)[0]);
        $spool->release($claim['path'], $claim['token']);
        $claim = $spool->claimReady($spool->findReady(1)[0]);
        $spool->acknowledge($claim['path'], $claim['token']);

        $this->assertCount(0, $spool->findReady(10));
        $this->assertFileDoesNotExist($claim['path']);
    }
}
