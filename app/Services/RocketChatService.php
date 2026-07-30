<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RocketChatService
{
    private static bool $isSending = false;

    /**
     * Send a system error notification to RocketChat.
     */
    public function sendSystemErrorAlert(Throwable $exception, ?int $ticketId = null): bool
    {
        if (self::$isSending) {
            return false;
        }

        self::$isSending = true;

        try {
            $appName = config('app.name', 'External Server Timer V34');
            $env = config('app.env', 'production');
            $timestamp = now()->toDateTimeString();
            $exceptionClass = get_class($exception);
            $message = $exception->getMessage() ?: 'No message provided';
            $file = $exception->getFile();
            $line = $exception->getLine();

            $title = "🚨 [CẢNH BÁO LỖI HỆ THỐNG] {$appName} ({$env})";
            $text = "### {$title}\n"
                . "- **Thời gian:** {$timestamp}\n"
                . "- **Lỗi:** `{$exceptionClass}`\n"
                . "- **Nội dung:** `{$message}`\n"
                . "- **File:** `{$file}:{$line}`\n"
                . ($ticketId ? "- **Ticket ID:** `{$ticketId}`\n" : "");

            return $this->sendMessage($text, [
                'color' => '#FF0000',
                'title' => 'System Error Details',
                'text' => "Message: {$message}\nFile: {$file}:{$line}" . ($ticketId ? "\nTicket ID: {$ticketId}" : ""),
            ]);
        } catch (Throwable $e) {
            Log::error('RocketChatService failed to send system error alert', [
                'error' => $e->getMessage(),
                'original_exception' => $exception->getMessage(),
            ]);
            return false;
        } finally {
            self::$isSending = false;
        }
    }

    /**
     * Send queue congestion alert to RocketChat.
     */
    public function sendQueueCongestionAlert(int $pendingJobsCount, int $threshold = 100): bool
    {
        if (self::$isSending) {
            return false;
        }

        self::$isSending = true;

        try {
            $appName = config('app.name', 'External Server Timer V34');
            $timestamp = now()->toDateTimeString();

            $title = "⚠️ [CẢNH BÁO NGHẼN HÀNG ĐỢI] {$appName}";
            $text = "### {$title}\n"
                . "- **Thời gian:** {$timestamp}\n"
                . "- **Số Job đang chờ (Pending Jobs):** `{$pendingJobsCount}`\n"
                . "- **Ngưỡng cảnh báo:** `{$threshold}`\n"
                . "- **Khuyến nghị:** Cần kiểm tra Worker hoặc mở rộng (Scale) container queue.";

            return $this->sendMessage($text, [
                'color' => '#FFA500',
                'title' => 'Queue Congestion Details',
                'text' => "Pending Jobs: {$pendingJobsCount} (Threshold: {$threshold})",
            ]);
        } catch (Throwable $e) {
            Log::error('RocketChatService failed to send queue congestion alert', [
                'error' => $e->getMessage(),
                'pending_jobs' => $pendingJobsCount,
            ]);
            return false;
        } finally {
            self::$isSending = false;
        }
    }

    /**
     * Internal method to dispatch HTTP message to RocketChat REST API or Webhook.
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

                return $response->successful();
            } catch (Throwable $e) {
                Log::warning('RocketChat webhook dispatch failed', ['error' => $e->getMessage()]);
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
                    } catch (Throwable $e) {
                        Log::warning('RocketChat REST API dispatch exception', [
                            'url' => $currentBaseUrl,
                            'channel' => $targetChannel,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return false;
        }

        Log::debug('RocketChat is not configured (neither WEBHOOK_URL nor USER_ID/TOKEN defined). Skipping notification.');
        return false;
    }
}
