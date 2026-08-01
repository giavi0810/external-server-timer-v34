<?php

namespace Tests\Unit;

use App\Models\RocketChatDeliveryStatus;
use App\Services\RocketChatService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

class RocketChatServiceTest extends TestCase
{
    private string $alertStateDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alertStateDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'v34-rocketchat-alert-test-'.bin2hex(random_bytes(8));

        config([
            'app.name' => 'TimerV34',
            'app.env' => 'production',
            'services.rocketchat.webhook_url' => null,
            'services.rocketchat.url' => 'http://rocketchat.test',
            'services.rocketchat.user_id' => 'user-id',
            'services.rocketchat.token' => 'token',
            'services.rocketchat.channel' => 'general',
            'services.rocketchat.alert_timezone' => 'Asia/Ho_Chi_Minh',
            'services.rocketchat.alert_dedup_seconds' => 300,
            'services.rocketchat.alert_global_rate_seconds' => 0,
            'services.rocketchat.alert_claim_seconds' => 30,
            'services.rocketchat.alert_state_path' => $this->alertStateDirectory.'/state.json',
            'services.rocketchat.redis_reminder_seconds' => 1800,
            'rocketchat_audit.enabled' => true,
            'rocketchat_audit.root' => $this->alertStateDirectory.'/audit',
            'rocketchat_audit.require_directory_fsync' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-30 06:49:06', 'UTC'));

        Http::fake([
            'http://rocketchat.test/api/v1/chat.postMessage' => Http::response([
                'success' => true,
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->alertStateDirectory);

        parent::tearDown();
    }

    public function test_database_alert_is_concise_and_manager_friendly(): void
    {
        $exception = new PDOException(
            'SQLSTATE[08006] [7] connection to server at "postgres", port 5432 failed: timeout expired '
            .'(Connection: pgsql, Host: postgres, Port: 5432, Database: timer_v34_db, '
            .'SQL: select * from "tickets" where "ticket_id" = 18317 limit 1)'
        );

        $result = app(RocketChatService::class)->sendSystemErrorAlert($exception, 18317);

        $this->assertTrue($result);
        $audit = $this->singleAuditEnvelope();
        $this->assertSame('POSTGRES_DOWN', $audit['event_code']);
        $this->assertSame('success', $audit['status']);

        Http::assertSent(function ($request): bool {
            $text = $request['text'];
            $attachment = $request['attachments'][0]['text'];

            return str_contains($text, '[NGHIÊM TRỌNG] Mất kết nối cơ sở dữ liệu')
                && str_contains($text, '30/07/2026 13:49:06 (GMT+7)')
                && str_contains($text, 'Ticket:** `#18317`')
                && str_contains($text, 'Ảnh hưởng')
                && str_contains($text, 'Khuyến nghị')
                && str_contains($attachment, 'Mã lỗi: 08006')
                && str_contains($attachment, 'Endpoint: postgres:5432')
                && ! str_contains($text, 'select * from')
                && ! str_contains($attachment, 'select * from');
        });
    }

    public function test_duplicate_alert_is_suppressed_within_configured_window(): void
    {
        $exception = new PDOException(
            'SQLSTATE[08006] [7] connection to server at "postgres", port 5432 failed: timeout expired'
        );

        $service = app(RocketChatService::class);

        $this->assertTrue($service->sendSystemErrorAlert($exception, 18317));
        $this->assertFalse($service->sendSystemErrorAlert($exception, 18318));

        Http::assertSentCount(1);
    }

    public function test_redis_down_alert_is_actionable_and_shared_file_dedup_suppresses_duplicates(): void
    {
        $exception = new \RedisException(
            'php_network_getaddresses: getaddrinfo for redis failed: Name does not resolve'
        );

        $this->assertTrue(app(RocketChatService::class)->sendSystemErrorAlert($exception));
        $this->assertFalse(app(RocketChatService::class)->sendSystemErrorAlert($exception));

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $text = $request['text'];
            $attachment = $request['attachments'][0]['text'];

            return str_contains($text, '[NGHIÊM TRỌNG] Mất kết nối Redis')
                && str_contains($text, 'Trạng thái:** `DOWN`')
                && str_contains($text, 'Webhook vẫn được giữ trong file spool')
                && str_contains($text, 'timer-v34-redis')
                && str_contains($attachment, 'Thành phần: Redis')
                && str_contains($attachment, 'Số lỗi đã gộp: 1');
        });

        $state = json_decode(
            file_get_contents($this->alertStateDirectory.'/state.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            'down',
            array_values($state['redis_incidents'])[0]['status']
        );
        $this->assertSame(
            2,
            array_values($state['redis_incidents'])[0]['occurrence_count']
        );
    }

    public function test_redis_down_reminder_is_sent_after_configured_interval(): void
    {
        $exception = new \RedisException('Redis connection refused');
        $service = app(RocketChatService::class);

        $this->assertTrue($service->sendSystemErrorAlert($exception));

        Carbon::setTestNow(now()->addSeconds(1799));
        $this->assertFalse($service->sendSystemErrorAlert($exception));

        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertTrue($service->sendSystemErrorAlert($exception));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'DOWN — NHẮC LẠI')
        );
    }

    public function test_redis_recovered_alert_is_sent_once_after_a_down_alert(): void
    {
        $service = app(RocketChatService::class);
        $exception = new \RedisException('Redis connection refused');

        $this->assertTrue($service->sendSystemErrorAlert($exception));

        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertTrue($service->sendRedisRecoveredAlert());
        $this->assertFalse($service->sendRedisRecoveredAlert());

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], '[KHÔI PHỤC] Kết nối Redis đã hoạt động lại')
                && str_contains($request['text'], 'Trạng thái:** `RECOVERED`')
        );

        $state = json_decode(
            file_get_contents($this->alertStateDirectory.'/state.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            'up',
            array_values($state['redis_incidents'])[0]['status']
        );
    }

    public function test_authentication_error_does_not_retry_channel_variants(): void
    {
        config()->set('services.rocketchat.url', 'http://unauthorized.rocketchat.test');

        Http::fake([
            'http://unauthorized.rocketchat.test/api/v1/chat.postMessage' => Http::response([
                'success' => false,
                'error' => 'You must be logged in to do this.',
            ], 401),
        ]);

        $this->assertFalse(app(RocketChatService::class)->sendMessage(
            'Authentication test',
            null,
            RocketChatDeliveryStatus::EVENT_SYSTEM_ERROR
        ));

        Http::assertSentCount(1);

        $audit = $this->singleAuditEnvelope();
        $this->assertSame('SYSTEM_ERROR', $audit['event_code']);
        $this->assertSame('failed', $audit['status']);
        $this->assertSame(401, $audit['http_status']);
        $this->assertSame(1, $audit['attempt_count']);
    }

    public function test_production_failure_tries_at_most_two_channel_candidates(): void
    {
        config()->set('services.rocketchat.url', 'http://failed.rocketchat.test');
        Http::fake([
            'http://failed.rocketchat.test/api/v1/chat.postMessage' => Http::response([
                'success' => false,
            ], 503),
        ]);

        $this->assertFalse(app(RocketChatService::class)->sendMessage(
            'Bounded retry test',
            null,
            RocketChatDeliveryStatus::EVENT_SYSTEM_ERROR
        ));

        Http::assertSentCount(2);

        $audit = $this->singleAuditEnvelope();
        $this->assertSame('failed', $audit['status']);
        $this->assertSame(503, $audit['http_status']);
        $this->assertSame(2, $audit['attempt_count']);
    }

    public function test_redis_down_keeps_incident_code_when_rocketchat_delivery_fails(): void
    {
        config([
            'services.rocketchat.webhook_url' => 'http://rocketchat-webhook.test',
            'services.rocketchat.url' => null,
            'services.rocketchat.user_id' => null,
            'services.rocketchat.token' => null,
        ]);
        Http::fake([
            'http://rocketchat-webhook.test' => Http::response([
                'success' => false,
            ], 503),
        ]);

        $result = app(RocketChatService::class)->sendSystemErrorAlert(
            new \RedisException('Redis connection refused')
        );

        $this->assertFalse($result);
        Http::assertSentCount(1);

        $audit = $this->singleAuditEnvelope();
        $this->assertSame(
            RocketChatDeliveryStatus::EVENT_REDIS_DOWN,
            $audit['event_code']
        );
        $this->assertSame('failed', $audit['status']);
        $this->assertSame(503, $audit['http_status']);
        $this->assertSame(1, $audit['attempt_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function singleAuditEnvelope(): array
    {
        $files = glob($this->alertStateDirectory.'/audit/ready/*.json') ?: [];
        $this->assertCount(1, $files);

        return json_decode(
            file_get_contents($files[0]),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}
