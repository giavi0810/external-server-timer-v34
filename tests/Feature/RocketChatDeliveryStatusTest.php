<?php

namespace Tests\Feature;

use App\Models\RocketChatDeliveryStatus;
use App\Services\Alerts\RocketChatAuditSpool;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class RocketChatDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'v34-rocketchat-audit-feature-'.bin2hex(random_bytes(8));
        config([
            'freshdesk.basic_auth.username' => 'qa-user',
            'freshdesk.basic_auth.password' => 'qa-password',
            'services.rocketchat.alert_timezone' => 'Asia/Ho_Chi_Minh',
            'rocketchat_audit.enabled' => true,
            'rocketchat_audit.root' => $this->root,
            'rocketchat_audit.require_directory_fsync' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-31 07:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_sync_is_idempotent_and_removes_file_only_after_db_commit(): void
    {
        $deliveryId = (string) Str::uuid();
        $spool = app(RocketChatAuditSpool::class);
        $spool->begin($deliveryId, RocketChatDeliveryStatus::EVENT_REDIS_DOWN);
        $spool->complete(
            $deliveryId,
            RocketChatDeliveryStatus::STATUS_SUCCESS,
            200,
            'rocket-message-id',
            1
        );

        $this->artisan('rocketchat-audit:sync --limit=10')->assertSuccessful();
        $this->assertDatabaseHas('rocket_chat_delivery_statuses', [
            'delivery_id' => $deliveryId,
            'event_code' => RocketChatDeliveryStatus::EVENT_REDIS_DOWN,
            'status' => RocketChatDeliveryStatus::STATUS_SUCCESS,
            'http_status' => 200,
            'attempt_count' => 1,
        ]);
        $this->assertCount(0, $spool->findReady(10));

        $this->artisan('rocketchat-audit:sync --limit=10')->assertSuccessful();
        $this->assertSame(1, RocketChatDeliveryStatus::query()->count());
    }

    public function test_admin_api_requires_auth_and_filters_without_exposing_content(): void
    {
        RocketChatDeliveryStatus::query()->create([
            'delivery_id' => (string) Str::uuid(),
            'event_code' => RocketChatDeliveryStatus::EVENT_REDIS_DOWN,
            'status' => RocketChatDeliveryStatus::STATUS_SUCCESS,
            'http_status' => 200,
            'rocketchat_message_id' => 'message-1',
            'attempt_count' => 1,
            'attempted_at' => now()->utc(),
            'completed_at' => now()->utc(),
        ]);
        RocketChatDeliveryStatus::query()->create([
            'delivery_id' => (string) Str::uuid(),
            'event_code' => RocketChatDeliveryStatus::EVENT_POSTGRES_DOWN,
            'status' => RocketChatDeliveryStatus::STATUS_FAILED,
            'http_status' => 503,
            'attempt_count' => 2,
            'attempted_at' => now()->utc(),
            'completed_at' => now()->utc(),
        ]);

        $this->getJson('/api/admin/rocket-chat-statuses')->assertUnauthorized();

        $response = $this->withBasicAuth('qa-user', 'qa-password')
            ->getJson(
                '/api/admin/rocket-chat-statuses'
                .'?date=2026-07-31&event_code=REDIS_DOWN&status=success&limit=10'
            )
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.success', 1)
            ->assertJsonPath('data.0.event_code', 'REDIS_DOWN')
            ->assertJsonPath('data.0.status', 'success');

        $payload = $response->json();
        $this->assertArrayNotHasKey('text', $payload['data'][0]);
        $this->assertArrayNotHasKey('exception', $payload['data'][0]);
        $this->assertArrayNotHasKey('payload', $payload['data'][0]);
    }
}
