<?php

namespace Tests\Unit;

use App\Services\RocketChatService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

class RocketChatServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'TimerV34',
            'app.env' => 'production',
            'cache.default' => 'array',
            'services.rocketchat.webhook_url' => null,
            'services.rocketchat.url' => 'http://rocketchat.test',
            'services.rocketchat.user_id' => 'user-id',
            'services.rocketchat.token' => 'token',
            'services.rocketchat.channel' => 'general',
            'services.rocketchat.alert_timezone' => 'Asia/Ho_Chi_Minh',
            'services.rocketchat.alert_dedup_seconds' => 300,
        ]);

        Cache::flush();
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

        parent::tearDown();
    }

    public function test_database_alert_is_concise_and_manager_friendly(): void
    {
        $exception = new PDOException(
            'SQLSTATE[08006] [7] connection to server at "postgres", port 5432 failed: timeout expired '
            . '(Connection: pgsql, Host: postgres, Port: 5432, Database: timer_v34_db, '
            . 'SQL: select * from "tickets" where "ticket_id" = 18317 limit 1)'
        );

        $result = app(RocketChatService::class)->sendSystemErrorAlert($exception, 18317);

        $this->assertTrue($result);

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
                && !str_contains($text, 'select * from')
                && !str_contains($attachment, 'select * from');
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
}
