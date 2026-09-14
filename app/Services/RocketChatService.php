<?php

namespace App\Services;

use App\Models\RocketChatDeliveryStatus;
use App\Services\Alerts\RocketChatAlertStateStore;
use App\Services\Alerts\RocketChatAuditSpool;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RocketChatService
{
    private static bool $isSending = false;

    private static array $fallbackLastSentAt = [];

    private static int $fallbackGlobalLastSentAt = 0;

    public function __construct(
        private readonly RocketChatAlertStateStore $alertStateStore,
        private readonly RocketChatAuditSpool $auditSpool
    ) {}

    /**
     * Send a manager-friendly system error notification to Rocket.Chat.
     */
    public function sendSystemErrorAlert(
        Throwable $exception,
        ?int $ticketId = null,
        ?string $task = null
    ): bool
    {
        if (self::$isSending) {
            return false;
        }

        self::$isSending = true;

        try {
            $alert = $this->buildIncidentAlert($exception, $ticketId, $task);

            if ($alert['category'] === 'redis_connection') {
                return $this->sendRedisDownAlert($exception, $alert);
            }

            $incident = $this->claimSystemIncident(
                $alert['fingerprint'],
                max(0, (int) config('services.rocketchat.alert_dedup_seconds', 300))
            );
            $alert = $this->renderIncidentAlert($alert, $incident);
            $this->logSystemIncident($exception, $alert, $incident);

            if (! $incident['should_send']) {
                Log::info('Duplicate RocketChat system alert suppressed', [
                    'fingerprint' => $alert['fingerprint'],
                    'category' => $alert['category'],
                    'ticket_id' => $alert['ticket_id'],
                    'lookup_code' => $incident['lookup_code'],
                    'occurrence_count' => $incident['occurrence_count'],
                ]);

                return false;
            }

            $sent = $this->sendMessage(
                $alert['text'],
                $alert['attachment'],
                $alert['event_code']
            );
            $this->finishSystemIncident($alert['fingerprint'], $incident, $sent);

            return $sent;
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

            $claim = $this->claimAlertNotification(
                'queue:'.$fingerprint,
                max(0, (int) config('services.rocketchat.alert_dedup_seconds', 300))
            );
            if ($claim === null) {
                Log::info('Duplicate RocketChat queue congestion alert suppressed', [
                    'pending_jobs' => $pendingJobsCount,
                    'threshold' => $threshold,
                ]);

                return false;
            }

            $title = "⚠️ [CẢNH BÁO] Hàng đợi xử lý đang quá tải — {$appName}";
            $text = "### {$title}\n"
                ."- **Thời gian:** {$timestamp}\n"
                ."- **Số tác vụ đang chờ:** `{$pendingJobsCount}`\n"
                ."- **Ngưỡng cảnh báo:** `{$threshold}`\n"
                ."- **Ảnh hưởng:** Webhook và đồng bộ SLA có thể xử lý chậm.\n"
                .'- **Khuyến nghị:** Kiểm tra queue worker và tài nguyên máy chủ.';

            $sent = $this->sendMessage($text, [
                'color' => '#FFA500',
                'title' => 'Chi tiết kỹ thuật',
                'text' => "Pending jobs: {$pendingJobsCount}\nThreshold: {$threshold}",
            ], RocketChatDeliveryStatus::EVENT_QUEUE_CONGESTION);
            $this->finishAlertNotification($claim, $sent);

            return $sent;
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
    public function sendMessage(
        string $text,
        ?array $attachment,
        string $eventCode
    ): bool {
        $deliveryId = (string) Str::uuid();
        $auditStarted = false;

        try {
            $this->auditSpool->begin($deliveryId, $eventCode);
            $auditStarted = true;
        } catch (Throwable $exception) {
            Log::warning('Unable to start RocketChat delivery audit', [
                'delivery_id' => $deliveryId,
                'event_code' => $eventCode,
                'error' => $exception->getMessage(),
            ]);
        }

        $result = $this->dispatchMessage($text, $attachment);

        if ($auditStarted) {
            try {
                $this->auditSpool->complete(
                    $deliveryId,
                    $result['success']
                        ? RocketChatDeliveryStatus::STATUS_SUCCESS
                        : RocketChatDeliveryStatus::STATUS_FAILED,
                    $result['http_status'],
                    $result['message_id'],
                    $result['attempt_count']
                );
            } catch (Throwable $exception) {
                Log::warning('Unable to complete RocketChat delivery audit', [
                    'delivery_id' => $deliveryId,
                    'event_code' => $eventCode,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result['success'];
    }

    /**
     * @return array{
     *     success: bool,
     *     http_status: int|null,
     *     message_id: string|null,
     *     attempt_count: int
     * }
     */
    private function dispatchMessage(string $text, ?array $attachment = null): array
    {
        $webhookUrl = config('services.rocketchat.webhook_url');
        $baseUrl = config('services.rocketchat.url');
        $userId = config('services.rocketchat.user_id');
        $token = config('services.rocketchat.token');
        $channel = config('services.rocketchat.channel') ?: 'GENERAL';
        $attachments = $attachment ? [$attachment] : [];
        $attemptCount = 0;
        $lastHttpStatus = null;

        if (! empty($webhookUrl)) {
            try {
                $attemptCount++;
                $response = Http::connectTimeout(1)->timeout(3)->post($webhookUrl, [
                    'text' => $text,
                    'attachments' => $attachments,
                ]);
                $lastHttpStatus = $response->status();

                if ($response->successful() && $response->json('success') !== false) {
                    Log::info('RocketChat webhook notification dispatched successfully');

                    return [
                        'success' => true,
                        'http_status' => $lastHttpStatus,
                        'message_id' => $this->extractMessageId($response->json()),
                        'attempt_count' => $attemptCount,
                    ];
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

        if (! empty($baseUrl) && ! empty($userId) && ! empty($token)) {
            $baseUrls = app()->isProduction()
                ? [$baseUrl]
                : array_slice(array_values(array_unique(array_filter([
                    $baseUrl,
                    str_replace(['localhost', '127.0.0.1'], 'host.docker.internal', $baseUrl),
                    str_replace(['localhost', '127.0.0.1'], '172.17.0.1', $baseUrl),
                    str_replace(['localhost', '127.0.0.1'], '172.22.0.1', $baseUrl),
                ]))), 0, 2);
            $headers = [
                'X-User-Id' => $userId,
                'X-Auth-Token' => $token,
                'Content-Type' => 'application/json',
            ];
            $cleanChannel = ltrim($channel, '#');
            $candidates = array_slice(array_values(array_unique(array_filter([
                $channel,
                $cleanChannel,
                '#'.$cleanChannel,
            ]))), 0, 2);

            foreach ($baseUrls as $currentBaseUrl) {
                $endpoint = rtrim($currentBaseUrl, '/').'/api/v1/chat.postMessage';

                foreach ($candidates as $targetChannel) {
                    try {
                        $attemptCount++;
                        $response = Http::connectTimeout(1)->timeout(3)
                            ->withHeaders($headers)
                            ->post($endpoint, [
                                'channel' => $targetChannel,
                                'text' => $text,
                                'attachments' => $attachments,
                            ]);
                        $lastHttpStatus = $response->status();

                        if ($response->successful() && $response->json('success') !== false) {
                            Log::info('RocketChat notification dispatched successfully', [
                                'url' => $currentBaseUrl,
                                'channel' => $targetChannel,
                            ]);

                            return [
                                'success' => true,
                                'http_status' => $lastHttpStatus,
                                'message_id' => $this->extractMessageId($response->json()),
                                'attempt_count' => $attemptCount,
                            ];
                        }

                        Log::warning('RocketChat REST API response error', [
                            'url' => $currentBaseUrl,
                            'channel' => $targetChannel,
                            'status' => $response->status(),
                            'response' => $response->json() ?? $response->body(),
                        ]);

                        if (in_array($response->status(), [401, 403], true)) {
                            return [
                                'success' => false,
                                'http_status' => $lastHttpStatus,
                                'message_id' => null,
                                'attempt_count' => $attemptCount,
                            ];
                        }
                    } catch (Throwable $exception) {
                        Log::warning('RocketChat REST API dispatch exception', [
                            'url' => $currentBaseUrl,
                            'channel' => $targetChannel,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            return [
                'success' => false,
                'http_status' => $lastHttpStatus,
                'message_id' => null,
                'attempt_count' => $attemptCount,
            ];
        }

        Log::debug('RocketChat is not configured (neither WEBHOOK_URL nor USER_ID/TOKEN defined). Skipping notification.');

        return [
            'success' => false,
            'http_status' => $lastHttpStatus,
            'message_id' => null,
            'attempt_count' => $attemptCount,
        ];
    }

    /**
     * Build an alert with a concise management summary and compact technical details.
     *
     * @return array{
     *     category: string,
     *     event_code: string,
     *     ticket_id: int|null,
     *     fingerprint: string,
     *     text: string,
     *     attachment: array<string, string>
     * }
     */
    /** @return array<string, mixed> */
    private function buildIncidentAlert(
        Throwable $exception,
        ?int $ticketId,
        ?string $task
    ): array {
        $rootException = $this->rootException($exception);
        $message = $rootException->getMessage() ?: $exception->getMessage() ?: 'Không có mô tả lỗi.';
        $sqlState = $this->extractSqlState($message) ?: $this->extractSqlState($exception->getMessage());
        $target = $this->extractDatabaseTarget($message.' '.$exception->getMessage());
        $location = $this->resolveBusinessLocation($exception);
        $ticketId ??= $this->resolveTicketId() ?? $this->extractTicketId($exception->getMessage());
        $task = trim((string) $task) ?: $this->inferTask($exception);
        $shortMessage = $this->shortErrorMessage($message);
        $databaseError = $this->isDatabaseConnectionError($exception, $exception->getMessage());
        $redisError = $this->isRedisConnectionError($exception, $exception->getMessage());
        $recoverError = str_contains($message, 'recover-processing')
            || str_contains($message, 'ticket-events:recover-processing');

        $category = match (true) {
            $redisError => 'redis_connection',
            $databaseError => 'database_connection',
            $recoverError => 'recover_processing_error',
            $sqlState === '23502' => 'database_not_null',
            $sqlState === '23503' => 'database_foreign_key',
            $sqlState === '23505' => 'database_duplicate',
            $sqlState === '22007' => 'database_invalid_datetime',
            $this->isHttpTimeoutError($message) => 'external_timeout',
            default => 'system_error',
        };
        $cause = $this->describeCause($category, $target, $shortMessage, $rootException);
        $environment = strtoupper((string) config('app.env', 'production'));
        $fingerprint = $redisError ? $this->redisIncidentFingerprint() : sha1(implode('|', array_filter([
            $environment,
            $category,
            $task,
            $exception::class,
            $rootException::class,
            $sqlState,
            $target['table'],
            $target['column'],
            $target['constraint'],
            $location,
            $category === 'system_error' ? $this->fingerprintMessage($shortMessage) : null,
        ])));

        return [
            'category' => $category,
            'event_code' => match (true) {
                $redisError => RocketChatDeliveryStatus::EVENT_REDIS_DOWN,
                $databaseError => RocketChatDeliveryStatus::EVENT_POSTGRES_DOWN,
                default => RocketChatDeliveryStatus::EVENT_SYSTEM_ERROR,
            },
            'environment' => $environment,
            'task' => $task,
            'ticket_id' => $ticketId,
            'cause' => $cause,
            'fingerprint' => $fingerprint,
            'technical_details' => array_filter([
                'Mã lỗi' => $sqlState ?: (string) ($rootException->getCode() ?: $exception->getCode() ?: 'N/A'),
                'Exception' => $exception::class,
                'Root exception' => $rootException === $exception ? null : $rootException::class,
                'Vị trí nghiệp vụ' => $location,
                'Bảng' => $target['table'],
                'Cột' => $target['column'],
                'Ràng buộc' => $target['constraint'],
                'Trace ID' => $this->resolveTraceId(),
                'Thông báo gốc' => $shortMessage,
            ], static fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /** @param array<string, mixed> $alert @param array<string, mixed> $incident */
    private function renderIncidentAlert(array $alert, array $incident): array
    {
        $ticket = $alert['ticket_id'] ? (string) $alert['ticket_id'] : 'Không áp dụng';
        $firstDetected = $this->formattedTimestampFromUnix($incident['first_detected_at']);
        $lastDetected = $this->formattedTimestampFromUnix($incident['last_detected_at']);
        $technical = collect($alert['technical_details'])
            ->map(static fn ($value, $label) => "{$label}: {$value}")
            ->implode("\n");
        $appName = config('app.name', 'External Server Timer V34');

        $alert['text'] = "### 🚨 [CẢNH BÁO] Lỗi ứng dụng — {$appName}\n"
            ."- **Môi trường:** `{$alert['environment']}`\n"
            ."- **Thời gian:** {$lastDetected}\n"
            ."- **Tác vụ:** {$alert['task']}\n"
            ."- **Ticket Freshdesk:** `{$ticket}`\n\n"
            ."**Nguyên nhân:**\n{$alert['cause']}\n\n"
            ."- **Mã tra cứu:** `{$incident['lookup_code']}`\n"
            ."- **Số lần lặp:** {$incident['occurrence_count']}\n"
            ."- **Lần đầu:** {$firstDetected}\n"
            ."- **Lần gần nhất:** {$lastDetected}";
        $alert['attachment'] = [
            'color' => in_array($alert['category'], ['redis_connection', 'database_connection'], true)
                ? '#D32F2F'
                : '#FF8F00',
            'title' => 'Chi tiết kỹ thuật',
            'text' => $technical,
        ];

        return $alert;
    }

    private function rootException(Throwable $exception): Throwable
    {
        $root = $exception;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }

        return $root;
    }

    /** @return array{table: string|null, column: string|null, constraint: string|null} */
    private function extractDatabaseTarget(string $message): array
    {
        preg_match('/column "([^"]+)"/i', $message, $column);
        preg_match('/relation "([^"]+)"/i', $message, $table);
        preg_match('/constraint "([^"]+)"/i', $message, $constraint);

        return [
            'table' => $table[1] ?? null,
            'column' => $column[1] ?? null,
            'constraint' => $constraint[1] ?? null,
        ];
    }

    /** @param array{table: string|null, column: string|null, constraint: string|null} $target */
    private function describeCause(
        string $category,
        array $target,
        string $shortMessage,
        Throwable $rootException
    ): string {
        $field = $target['table'] && $target['column']
            ? "`{$target['table']}.{$target['column']}`"
            : ($target['column'] ? "`{$target['column']}`" : 'một trường bắt buộc');

        return match ($category) {
            'redis_connection' => 'Ứng dụng không thể kết nối tới Redis.',
            'database_connection' => 'Ứng dụng không thể kết nối tới PostgreSQL.',
            'recover_processing_error' => "Tiến trình tự động khôi phục hàng chờ thất bại: {$shortMessage}",
            'database_not_null' => "Không thể lưu dữ liệu vì {$field} đang trống, vi phạm ràng buộc bắt buộc của PostgreSQL.",
            'database_foreign_key' => 'Không thể lưu dữ liệu vì bản ghi tham chiếu chưa tồn tại hoặc không còn hợp lệ.',
            'database_duplicate' => 'Không thể lưu dữ liệu vì giá trị đã tồn tại và vi phạm ràng buộc duy nhất.',
            'database_invalid_datetime' => 'Không thể lưu dữ liệu vì giá trị ngày giờ không đúng định dạng.',
            'external_timeout' => "Dịch vụ bên ngoài không phản hồi trong thời gian cho phép: {$shortMessage}",
            default => class_basename($rootException).": {$shortMessage}",
        };
    }

    private function shortErrorMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?: $message;
        $message = preg_replace('/\s+DETAIL:\s+Failing row contains.*$/i', '', $message) ?: $message;
        $message = preg_replace('/\s*\(Connection:\s.*$/i', '', $message) ?: $message;
        $message = preg_replace('/\s+SQL:\s+.*$/i', '', $message) ?: $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $message) ?: $message;
        $message = preg_replace('/(["\']?(?:password|token|secret)["\']?\s*[:=]\s*)["\']?[^\s,;}"\']+/i', '$1[REDACTED]', $message) ?: $message;

        return Str::limit(trim($message), 500);
    }

    private function fingerprintMessage(string $message): string
    {
        $message = strtolower($message);
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27,}/i', '{uuid}', $message) ?: $message;
        $message = preg_replace('/\b[0-9]{2,}\b/', '{n}', $message) ?: $message;

        return Str::limit($message, 300, '');
    }

    private function resolveBusinessLocation(Throwable $exception): string
    {
        $frames = array_merge([[
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]], $exception->getTrace());
        $appPath = rtrim(str_replace('\\', '/', app_path()), '/').'/';

        foreach ($frames as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file !== '' && str_starts_with($file, $appPath)) {
                return basename($file).':'.($frame['line'] ?? '?');
            }
        }

        return basename($exception->getFile()).':'.$exception->getLine();
    }

    private function inferTask(Throwable $exception): string
    {
        $traceClasses = collect($exception->getTrace())->pluck('class')->filter()->implode('|');

        return match (true) {
            str_contains($traceClasses, 'PersistFreshdeskWebhookJob'),
            str_contains($traceClasses, 'WebhookController') => 'Đồng bộ ticket từ Freshdesk',
            str_contains($traceClasses, 'ProcessTicketEventJob') => 'Xử lý SLA ticket Freshdesk',
            str_contains($traceClasses, 'RocketChatAudit') => 'Đồng bộ nhật ký Rocket.Chat',
            str_contains($traceClasses, 'RecoverTicketEventProcessing') => 'Khôi phục hàng chờ xử lý ticket',
            $this->isRedisConnectionError($exception, $exception->getMessage()) => 'Giám sát Redis',
            default => 'Xử lý tác vụ ứng dụng',
        };
    }

    private function extractTicketId(string $message): ?int
    {
        if (preg_match('/["`]ticket_id["`]?\s*=\s*([0-9]+)/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }
        if (preg_match('/relation "tickets".*?Failing row contains \(([0-9]+)/is', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function isHttpTimeoutError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout was reached')
            || str_contains($message, 'operation timeout');
    }

    private function buildSystemErrorAlert(Throwable $exception, ?int $ticketId): array
    {
        $message = $exception->getMessage() ?: 'Không có mô tả lỗi.';
        $ticketId ??= $this->resolveTicketId();
        $traceId = $this->resolveTraceId();
        $redisError = $this->isRedisConnectionError($exception, $message);
        $databaseError = ! $redisError && $this->isDatabaseConnectionError($exception, $message);

        $isRecoverProcessingError = str_contains($message, 'recover-processing') || str_contains($message, 'ticket-events:recover-processing');

        $category = match (true) {
            $redisError => 'redis_connection',
            $databaseError => 'database_connection',
            $isRecoverProcessingError => 'recover_processing_error',
            default => 'system_error',
        };
        $severity = ($redisError || $databaseError) ? 'NGHIÊM TRỌNG' : 'CẢNH BÁO';
        $headline = match (true) {
            $redisError => 'Mất kết nối Redis Queue',
            $databaseError => 'Mất kết nối CSDL PostgreSQL',
            $isRecoverProcessingError => 'Lỗi tiến trình tự động khôi phục (Recover Processing)',
            default => 'Phát hiện lỗi ứng dụng',
        };
        $impact = match (true) {
            $redisError => 'Tác vụ tạm ngừng. Webhook Freshdesk vẫn lưu an toàn trên đĩa đệm chờ khôi phục.',
            $databaseError => 'Tác vụ tạm ngừng. Sự cố Freshdesk vẫn lưu an toàn trên đĩa đệm và tự chạy lại khi có CSDL.',
            $isRecoverProcessingError => 'Tạm dừng dọn hàng chờ. Dữ liệu sự cố Freshdesk vẫn lưu an toàn trên đĩa đệm và tự chạy lại khi có CSDL.',
            default => 'Tác vụ hiện tại gián đoạn. Dữ liệu sự cố trên đĩa đệm vẫn được bảo toàn.',
        };
        $recommendation = match (true) {
            $redisError => 'Kiểm tra container timer-v34-redis trong Docker và mạng kết nối Redis.',
            $databaseError => 'Kiểm tra container timer-v34-postgres trong Docker và bật lại CSDL.',
            $isRecoverProcessingError => 'Kiểm tra kết nối CSDL PostgreSQL hoặc khởi động lại container timer-v34-postgres.',
            default => 'Tra cứu chi tiết vị trí lỗi bên dưới để xử lý.',
        };

        $appName = config('app.name', 'External Server Timer V34');
        $environment = strtoupper((string) config('app.env', 'production'));
        $lookupId = $traceId ?: 'Không có';
        $ticketLine = $ticketId ? "- **Ticket:** `#{$ticketId}`\n" : '';

        $title = "🚨 [{$severity}] {$headline} — {$appName}";
        $text = "### {$title}\n"
            ."- **Môi trường:** `{$environment}`\n"
            ."- **Thời gian:** {$this->formattedTimestamp()}\n"
            .$ticketLine
            ."- **Ảnh hưởng:** {$impact}\n"
            ."- **Trạng thái:** Cần kiểm tra\n"
            ."- **Khuyến nghị:** {$recommendation}\n"
            ."- **Mã tra cứu:** `{$lookupId}`";

        $technicalDetails = [
            'Mã lỗi' => $this->extractSqlState($message) ?: (string) ($exception->getCode() ?: 'N/A'),
            'Thành phần' => match (true) {
                $redisError => 'Redis',
                $databaseError => 'PostgreSQL',
                default => class_basename($exception),
            },
            'Endpoint' => $databaseError ? $this->extractDatabaseEndpoint($message) : null,
            'Exception' => $exception::class,
            'Vị trí' => basename($exception->getFile()).':'.$exception->getLine(),
            'Ticket' => $ticketId ? "#{$ticketId}" : null,
            'Mã tra cứu' => $traceId,
        ];

        if (! $databaseError) {
            $technicalDetails['Mô tả rút gọn'] = Str::limit(
                preg_replace('/\s+/', ' ', $message) ?: $message,
                300
            );
        }

        $attachmentText = collect($technicalDetails)
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->map(static fn ($value, $label) => "{$label}: {$value}")
            ->implode("\n");

        $fingerprintParts = $redisError
            ? [$category, $environment]
            : [
                $category,
                $exception::class,
                $this->extractSqlState($message),
                $this->extractDatabaseEndpoint($message),
            ];

        return [
            'category' => $category,
            'event_code' => match ($category) {
                'redis_connection' => RocketChatDeliveryStatus::EVENT_REDIS_DOWN,
                'database_connection' => RocketChatDeliveryStatus::EVENT_POSTGRES_DOWN,
                default => RocketChatDeliveryStatus::EVENT_SYSTEM_ERROR,
            },
            'ticket_id' => $ticketId,
            'fingerprint' => sha1(implode('|', array_filter($fingerprintParts))),
            'text' => $text,
            'attachment' => [
                'color' => ($redisError || $databaseError) ? '#D32F2F' : '#FF8F00',
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

    private function isRedisConnectionError(Throwable $exception, string $message): bool
    {
        $lowerMessage = strtolower($message);
        $exceptionClass = strtolower($exception::class);

        return str_contains($exceptionClass, 'redisexception')
            || str_contains($lowerMessage, 'redis::connect')
            || str_contains($lowerMessage, 'getaddrinfo for redis failed')
            || (
                str_contains($lowerMessage, 'redis')
                && (
                    str_contains($lowerMessage, 'connection refused')
                    || str_contains($lowerMessage, 'connection timed out')
                    || str_contains($lowerMessage, 'name does not resolve')
                    || str_contains($lowerMessage, 'no such host')
                )
            );
    }

    /**
     * Called by the Redis health monitor after a successful PING.
     */
    public function sendRedisRecoveredAlert(): bool
    {
        if (self::$isSending) {
            return false;
        }

        self::$isSending = true;
        $fingerprint = $this->redisIncidentFingerprint();

        try {
            $incident = $this->alertStateStore->claimRedisRecovered(
                $fingerprint,
                $this->globalRateSeconds()
            );
            if (! $incident['should_send'] || $incident['token'] === null) {
                return false;
            }

            $appName = config('app.name', 'External Server Timer V34');
            $environment = strtoupper((string) config('app.env', 'production'));
            $duration = $this->humanDuration($incident['duration_seconds']);
            $text = "### ✅ [KHÔI PHỤC] Kết nối Redis đã hoạt động lại — {$appName}\n"
                ."- **Môi trường:** `{$environment}`\n"
                ."- **Thời gian phục hồi:** {$this->formattedTimestamp()}\n"
                ."- **Trạng thái:** `RECOVERED`\n"
                ."- **Thời gian gián đoạn:** {$duration}\n"
                ."- **Số lỗi đã gộp:** {$incident['occurrence_count']}\n"
                .'- **Kết quả:** Scheduler và worker có thể tiếp tục xử lý backlog.';
            $attachment = [
                'color' => '#2E7D32',
                'title' => 'Chi tiết phục hồi',
                'text' => "Thành phần: Redis\n"
                    ."Phát hiện lần đầu: {$this->formattedTimestampFromUnix($incident['first_detected_at'])}\n"
                    ."Phục hồi: {$this->formattedTimestampFromUnix($incident['recovered_at'])}\n"
                    ."Số lỗi đã gộp: {$incident['occurrence_count']}",
            ];

            $sent = $this->sendMessage(
                $text,
                $attachment,
                RocketChatDeliveryStatus::EVENT_REDIS_RECOVERED
            );
            if ($sent) {
                $this->alertStateStore->completeRedisRecovered($fingerprint, $incident['token']);
            } else {
                $this->alertStateStore->abandonRedisClaim($fingerprint, $incident['token']);
            }

            return $sent;
        } catch (Throwable $exception) {
            Log::warning('Unable to process RocketChat Redis recovery alert', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            self::$isSending = false;
        }
    }

    /**
     * @param array{
     *     category: string,
     *     event_code: string,
     *     ticket_id: int|null,
     *     fingerprint: string,
     *     text: string,
     *     attachment: array<string, string>
     * } $alert
     */
    private function sendRedisDownAlert(Throwable $exception, array $alert): bool
    {
        try {
            $incident = $this->alertStateStore->claimRedisDown(
                $alert['fingerprint'],
                max(60, (int) config('services.rocketchat.redis_reminder_seconds', 1800)),
                $this->globalRateSeconds()
            );
        } catch (Throwable $stateException) {
            Log::warning('RocketChat file alert state unavailable; using process-local fallback', [
                'error' => $stateException->getMessage(),
            ]);

            $claim = $this->claimFallbackNotification(
                'redis-down:'.$alert['fingerprint'],
                max(60, (int) config('services.rocketchat.redis_reminder_seconds', 1800))
            );
            $now = now()->timestamp;
            $timezone = (string) config('services.rocketchat.alert_timezone', 'Asia/Ho_Chi_Minh');
            $incident = [
                'should_send' => $claim !== null,
                'token' => $claim['token'] ?? null,
                'lookup_code' => 'EVT-'.now($timezone)->format('Ymd-His').'-'.Str::upper(Str::random(6)),
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'occurrence_count' => 1,
            ];
            $alert = $this->renderIncidentAlert($alert, $incident);
            $this->logSystemIncident($exception, $alert, $incident);

            if ($claim === null) {
                return false;
            }

            $sent = $this->sendMessage(
                $alert['text'],
                $alert['attachment'],
                $alert['event_code']
            );
            $this->finishAlertNotification($claim, $sent);

            return $sent;
        }

        $alert = $this->renderIncidentAlert($alert, $incident);
        $this->logSystemIncident($exception, $alert, $incident);

        if (! $incident['should_send'] || $incident['token'] === null) {
            Log::debug('Duplicate RocketChat Redis DOWN alert suppressed', [
                'fingerprint' => $alert['fingerprint'],
                'occurrence_count' => $incident['occurrence_count'],
            ]);

            return false;
        }

        $sent = $this->sendMessage(
            $alert['text'],
            $alert['attachment'],
            $alert['event_code']
        );
        if ($sent) {
            $this->alertStateStore->completeRedisDown($alert['fingerprint'], $incident['token']);
        } else {
            $this->alertStateStore->abandonRedisClaim($alert['fingerprint'], $incident['token']);
        }

        return $sent;
    }

    private function extractMessageId(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $messageId = data_get($payload, 'message._id')
            ?? data_get($payload, 'message.id')
            ?? data_get($payload, '_id');

        return is_scalar($messageId) && (string) $messageId !== ''
            ? Str::limit((string) $messageId, 191, '')
            : null;
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
        if (! app()->bound('request')) {
            return null;
        }

        $ticketId = request()->integer('ticket_id');

        return $ticketId > 0 ? $ticketId : null;
    }

    private function resolveTraceId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->attributes->get('trace_id')
            ?: $request->header('X-Trace-ID')
            ?: $request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID');
    }

    private function formattedTimestamp(): string
    {
        $timezone = config('services.rocketchat.alert_timezone', 'Asia/Ho_Chi_Minh');

        return now()->timezone($timezone)->format('d/m/Y H:i:s').' (GMT+7)';
    }

    private function formattedTimestampFromUnix(?int $timestamp): string
    {
        if ($timestamp === null) {
            return 'Không xác định';
        }

        $timezone = config('services.rocketchat.alert_timezone', 'Asia/Ho_Chi_Minh');

        return Carbon::createFromTimestampUTC($timestamp)
            ->timezone($timezone)
            ->format('d/m/Y H:i:s').' (GMT+7)';
    }

    /** @return array<string, mixed> */
    private function claimSystemIncident(string $fingerprint, int $dedupSeconds): array
    {
        try {
            return ['backend' => 'file'] + $this->alertStateStore->claimSystemIncident(
                $fingerprint,
                $dedupSeconds,
                $this->globalRateSeconds()
            );
        } catch (Throwable $stateException) {
            Log::warning('RocketChat incident state unavailable; using process-local fallback', [
                'error' => $stateException->getMessage(),
            ]);
            $claim = $this->claimFallbackNotification('system:'.$fingerprint, $dedupSeconds);
            $now = now()->timestamp;
            $timezone = (string) config('services.rocketchat.alert_timezone', 'Asia/Ho_Chi_Minh');

            return [
                'backend' => 'memory',
                'should_send' => $claim !== null,
                'token' => $claim['token'] ?? null,
                'lookup_code' => 'EVT-'.now($timezone)->format('Ymd-His').'-'.Str::upper(Str::random(6)),
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'occurrence_count' => 1,
                'fallback_claim' => $claim,
            ];
        }
    }

    /** @param array<string, mixed> $incident */
    private function finishSystemIncident(string $fingerprint, array $incident, bool $sent): void
    {
        if (($incident['backend'] ?? null) === 'file' && is_string($incident['token'] ?? null)) {
            try {
                if ($sent) {
                    $this->alertStateStore->completeSystemIncident($fingerprint, $incident['token']);
                } else {
                    $this->alertStateStore->abandonSystemIncident($fingerprint, $incident['token']);
                }
            } catch (Throwable $stateException) {
                Log::warning('Unable to finalize RocketChat system incident state', [
                    'lookup_code' => $incident['lookup_code'] ?? null,
                    'error' => $stateException->getMessage(),
                ]);
            }

            return;
        }

        if (is_array($incident['fallback_claim'] ?? null)) {
            $this->finishAlertNotification($incident['fallback_claim'], $sent);
        }
    }

    /** @param array<string, mixed> $alert @param array<string, mixed> $incident */
    private function logSystemIncident(Throwable $exception, array $alert, array $incident): void
    {
        Log::error('Application error incident detected', [
            'lookup_code' => $incident['lookup_code'],
            'fingerprint' => $alert['fingerprint'],
            'occurrence_count' => $incident['occurrence_count'],
            'first_detected_at' => $incident['first_detected_at'],
            'last_detected_at' => $incident['last_detected_at'],
            'task' => $alert['task'],
            'ticket_id' => $alert['ticket_id'],
            'category' => $alert['category'],
            'technical_details' => $alert['technical_details'],
            'exception' => $exception,
        ]);
    }

    /**
     * @return array{backend: string, key: string, token: string, reserved_at?: int}|null
     */
    private function claimAlertNotification(string $key, int $dedupSeconds): ?array
    {
        try {
            $claim = $this->alertStateStore->claimNotification(
                $key,
                $dedupSeconds,
                $this->globalRateSeconds()
            );

            return $claim === null
                ? null
                : ['backend' => 'file', 'key' => $key, 'token' => $claim['token']];
        } catch (Throwable $stateException) {
            Log::warning('RocketChat file alert state unavailable; using process-local fallback', [
                'error' => $stateException->getMessage(),
            ]);

            return $this->claimFallbackNotification($key, $dedupSeconds);
        }
    }

    /**
     * @return array{backend: string, key: string, token: string, reserved_at: int}|null
     */
    private function claimFallbackNotification(string $key, int $dedupSeconds): ?array
    {
        $now = now()->timestamp;
        if ((self::$fallbackLastSentAt[$key] ?? 0) + $dedupSeconds > $now
            || self::$fallbackGlobalLastSentAt + $this->globalRateSeconds() > $now
        ) {
            return null;
        }

        self::$fallbackLastSentAt[$key] = $now;
        self::$fallbackGlobalLastSentAt = $now;

        return [
            'backend' => 'memory',
            'key' => $key,
            'token' => (string) Str::uuid(),
            'reserved_at' => $now,
        ];
    }

    /**
     * @param  array{backend: string, key: string, token: string, reserved_at?: int}  $claim
     */
    private function finishAlertNotification(array $claim, bool $sent): void
    {
        if ($claim['backend'] === 'file') {
            try {
                if ($sent) {
                    $this->alertStateStore->completeNotification($claim['key'], $claim['token']);
                } else {
                    $this->alertStateStore->abandonNotification($claim['key'], $claim['token']);
                }
            } catch (Throwable $stateException) {
                Log::warning('Unable to finalize RocketChat file alert state', [
                    'error' => $stateException->getMessage(),
                    'key' => $claim['key'],
                ]);
            }

            return;
        }

        if (! $sent && (self::$fallbackLastSentAt[$claim['key']] ?? null) === ($claim['reserved_at'] ?? null)) {
            unset(self::$fallbackLastSentAt[$claim['key']]);
            if (self::$fallbackGlobalLastSentAt === ($claim['reserved_at'] ?? null)) {
                self::$fallbackGlobalLastSentAt = 0;
            }
        }
    }

    private function redisIncidentFingerprint(): string
    {
        $environment = strtoupper((string) config('app.env', 'production'));

        return sha1("redis_connection|{$environment}");
    }

    private function globalRateSeconds(): int
    {
        return max(0, (int) config('services.rocketchat.alert_global_rate_seconds', 60));
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} giây";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' phút '.($seconds % 60).' giây';
        }

        return intdiv($seconds, 3600).' giờ '.intdiv($seconds % 3600, 60).' phút';
    }
}
