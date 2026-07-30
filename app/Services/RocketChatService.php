<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RocketChatService
{
    private static bool $isSending = false;

    /**
     * Send a manager-friendly system error notification to Rocket.Chat.
     */
    public function sendSystemErrorAlert(Throwable $exception, ?int $ticketId = null): bool
    {
        if (self::$isSending) {
            return false;
        }

        self::$isSending = true;

        try {
            $alert = $this->buildSystemErrorAlert($exception, $ticketId);

            if ($this->shouldSuppressDuplicate($alert['fingerprint'])) {
                Log::info('Duplicate RocketChat system alert suppressed', [
                    'fingerprint' => $alert['fingerprint'],
                    'category' => $alert['category'],
                    'ticket_id' => $alert['ticket_id'],
                ]);

                return false;
            }

            return $this->sendMessage($alert['text'], $alert['attachment']);
        } catch (Throwable $sendException) {
            Log::error('RocketChatService failed to send system error alert', [
                'error' => $sendException->getMessage(),
                'original_exception' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            self::$isSending = false;
        }
    }

    /**
     * Send queue congestion alert to Rocket.Chat.
     */
    public function sendQueueCongestionAlert(int $pendingJobsCount, int $threshold = 100): bool
    {
        if (self::$isSending) {
            return false;
        }

        self::$isSending = true;

        try {
            $appName = config('app.name', 'External Server Timer V34');
            $timestamp = $this->formattedTimestamp();
            $fingerprint = sha1("queue_congestion|{$threshold}");

            if ($this->shouldSuppressDuplicate($fingerprint)) {
                Log::info('Duplicate RocketChat queue congestion alert suppressed', [
                    'pending_jobs' => $pendingJobsCount,
                    'threshold' => $threshold,
                ]);

                return false;
            }

            $title = "⚠️ [CẢNH BÁO] Hàng đợi xử lý đang quá tải — {$appName}";
            $text = "### {$title}\n"
                . "- **Thời gian:** {$timestamp}\n"
                . "- **Số tác vụ đang chờ:** `{$pendingJobsCount}`\n"
                . "- **Ngưỡng cảnh báo:** `{$threshold}`\n"
                . "- **Ảnh hưởng:** Webhook và đồng bộ SLA có thể xử lý chậm.\n"
                . "- **Khuyến nghị:** Kiểm tra queue worker và tài nguyên máy chủ.";

            return $this->sendMessage($text, [
                'color' => '#FFA500',
                'title' => 'Chi tiết kỹ thuật',
                'text' => "Pending jobs: {$pendingJobsCount}\nThreshold: {$threshold}",
            ]);
        } catch (Throwable $sendException) {
            Log::error('RocketChatService failed to send queue congestion alert', [
                'error' => $sendException->getMessage(),
                'pending_jobs' => $pendingJobsCount,
            ]);

            return false;
        } finally {
            self::$isSending = false;
        }
    }

    /**
     * Internal method to dispatch an HTTP message to Rocket.Chat REST API or webhook.
     */
    public function sendMessage(string $text, ?array $attachment = null): bool
    {
        $webhookUrl = config('services.rocketchat.webhook_url');
        $baseUrl = config('services.rocketchat.url');
        $userId = config('services.rocketchat.user_id');
        $token = config('services.rocketchat.token');
        $channel = config('services.rocketchat.channel') ?: 'GENERAL';

        $attachments = $attachment ? [$attachment] : [];

        if (!empty($webhookUrl)) {
            try {
                $response = Http::timeout(5)->post($webhookUrl, [
                    'text' => $text,
                    'attachments' => $attachments,
                ]);

                if ($response->successful()) {
                    Log::info('RocketChat webhook notification dispatched successfully');

                    return true;
                }

                Log::warning('RocketChat webhook response error', [
                    'status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('RocketChat webhook dispatch failed', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (!empty($baseUrl) && !empty($userId) && !empty($token)) {
            $baseUrls = array_unique(array_filter([
                $baseUrl,
                str_replace(['localhost', '127.0.0.1'], 'host.docker.internal', $baseUrl),
                str_replace(['localhost', '127.0.0.1'], '172.17.0.1', $baseUrl),
                str_replace(['localhost', '127.0.0.1'], '172.22.0.1', $baseUrl),
            ]));

            $headers = [
                'X-User-Id' => $userId,
                'X-Auth-Token' => $token,
                'Content-Type' => 'application/json',
            ];

            $cleanChannel = ltrim($channel, '#');
            $candidates = array_unique([
                strtolower($cleanChannel),
                '#' . strtolower($cleanChannel),
                $channel,
                $cleanChannel,
                strtoupper($cleanChannel),
                '#' . strtoupper($cleanChannel),
            ]);

            foreach ($baseUrls as $currentBaseUrl) {
                $endpoint = rtrim($currentBaseUrl, '/') . '/api/v1/chat.postMessage';

                foreach ($candidates as $targetChannel) {
                    try {
                        $response = Http::timeout(3)
                            ->withHeaders($headers)
                            ->post($endpoint, [
                                'channel' => $targetChannel,
                                'text' => $text,
                                'attachments' => $attachments,
                            ]);

                        if ($response->successful()) {
                            Log::info('RocketChat notification dispatched successfully', [
                                'url' => $currentBaseUrl,
                                'channel' => $targetChannel,
                            ]);

                            return true;
                        }

                        Log::warning('RocketChat REST API response error', [
                            'url' => $currentBaseUrl,
                            'channel' => $targetChannel,
                            'status' => $response->status(),
                            'response' => $response->json() ?? $response->body(),
                        ]);
                    } catch (Throwable $exception) {
                        Log::warning('RocketChat REST API dispatch exception', [
                            'url' => $currentBaseUrl,
                            'channel' => $targetChannel,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            return false;
        }

        Log::debug('RocketChat is not configured (neither WEBHOOK_URL nor USER_ID/TOKEN defined). Skipping notification.');

        return false;
    }

    /**
     * Build an alert with a concise management summary and compact technical details.
     *
     * @return array{
     *     category: string,
     *     ticket_id: int|null,
     *     fingerprint: string,
     *     text: string,
     *     attachment: array<string, string>
     * }
     */
    private function buildSystemErrorAlert(Throwable $exception, ?int $ticketId): array
    {
        $message = $exception->getMessage() ?: 'Không có mô tả lỗi.';
        $ticketId ??= $this->resolveTicketId();
        $traceId = $this->resolveTraceId();
        $databaseError = $this->isDatabaseConnectionError($exception, $message);

        $category = $databaseError ? 'database_connection' : 'system_error';
        $severity = $databaseError ? 'NGHIÊM TRỌNG' : 'CẢNH BÁO';
        $headline = $databaseError ? 'Mất kết nối cơ sở dữ liệu' : 'Lỗi hệ thống cần kiểm tra';
        $impact = $databaseError
            ? 'Webhook hoặc tác vụ hiện tại xử lý thất bại; dữ liệu SLA có thể chưa được cập nhật.'
            : 'Tác vụ hiện tại có thể chưa hoàn tất.';
        $recommendation = $databaseError
            ? 'Kiểm tra PostgreSQL, Docker network và trạng thái container; xử lý rồi gửi lại webhook/job.'
            : 'Tra cứu log theo mã bên dưới để xác định nguyên nhân và xử lý.';

        $appName = config('app.name', 'External Server Timer V34');
        $environment = strtoupper((string) config('app.env', 'production'));
        $lookupId = $traceId ?: 'Không có';
        $ticketLine = $ticketId ? "- **Ticket:** `#{$ticketId}`\n" : '';

        $title = "🚨 [{$severity}] {$headline} — {$appName}";
        $text = "### {$title}\n"
            . "- **Môi trường:** `{$environment}`\n"
            . "- **Thời gian:** {$this->formattedTimestamp()}\n"
            . $ticketLine
            . "- **Ảnh hưởng:** {$impact}\n"
            . "- **Trạng thái:** Cần kiểm tra\n"
            . "- **Khuyến nghị:** {$recommendation}\n"
            . "- **Mã tra cứu:** `{$lookupId}`";

        $technicalDetails = [
            'Mã lỗi' => $this->extractSqlState($message) ?: (string) ($exception->getCode() ?: 'N/A'),
            'Thành phần' => $databaseError ? 'PostgreSQL' : class_basename($exception),
            'Endpoint' => $databaseError ? $this->extractDatabaseEndpoint($message) : null,
            'Exception' => $exception::class,
            'Vị trí' => basename($exception->getFile()) . ':' . $exception->getLine(),
            'Ticket' => $ticketId ? "#{$ticketId}" : null,
            'Mã tra cứu' => $traceId,
        ];

        if (!$databaseError) {
            $technicalDetails['Mô tả rút gọn'] = Str::limit(
                preg_replace('/\s+/', ' ', $message) ?: $message,
                300
            );
        }

        $attachmentText = collect($technicalDetails)
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->map(static fn ($value, $label) => "{$label}: {$value}")
            ->implode("\n");

        $fingerprintParts = [
            $category,
            $exception::class,
            $this->extractSqlState($message),
            $this->extractDatabaseEndpoint($message),
        ];

        return [
            'category' => $category,
            'ticket_id' => $ticketId,
            'fingerprint' => sha1(implode('|', array_filter($fingerprintParts))),
            'text' => $text,
            'attachment' => [
                'color' => $databaseError ? '#D32F2F' : '#FF8F00',
                'title' => 'Chi tiết kỹ thuật',
                'text' => $attachmentText,
            ],
        ];
    }

    private function isDatabaseConnectionError(Throwable $exception, string $message): bool
    {
        return str_contains($message, 'SQLSTATE[08006]')
            || str_contains($message, 'could not translate host name')
            || (
                str_contains($exception::class, 'PDOException')
                && str_contains(strtolower($message), 'connection')
            );
    }

    private function extractSqlState(string $message): ?string
    {
        return preg_match('/SQLSTATE\[([A-Z0-9]+)\]/', $message, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function extractDatabaseEndpoint(string $message): ?string
    {
        if (preg_match('/Host:\s*([^,\s]+),\s*Port:\s*(\d+)/i', $message, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        if (preg_match('/server at\s+"([^"]+)".*?port\s+(\d+)/is', $message, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        return null;
    }

    private function resolveTicketId(): ?int
    {
        if (!app()->bound('request')) {
            return null;
        }

        $ticketId = request()->integer('ticket_id');

        return $ticketId > 0 ? $ticketId : null;
    }

    private function resolveTraceId(): ?string
    {
        if (!app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->header('X-Trace-ID')
            ?: $request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID');
    }

    private function formattedTimestamp(): string
    {
        $timezone = config('services.rocketchat.alert_timezone', 'Asia/Ho_Chi_Minh');

        return now()->timezone($timezone)->format('d/m/Y H:i:s') . ' (GMT+7)';
    }

    private function shouldSuppressDuplicate(string $fingerprint): bool
    {
        $seconds = max(0, (int) config('services.rocketchat.alert_dedup_seconds', 300));

        if ($seconds === 0) {
            return false;
        }

        try {
            return !Cache::add("rocketchat:alert:{$fingerprint}", true, $seconds);
        } catch (Throwable $cacheException) {
            Log::warning('RocketChat alert deduplication unavailable', [
                'error' => $cacheException->getMessage(),
            ]);

            return false;
        }
    }
}
