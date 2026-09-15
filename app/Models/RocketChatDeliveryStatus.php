<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RocketChatDeliveryStatus extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const EVENT_REDIS_DOWN = 'REDIS_DOWN';

    public const EVENT_REDIS_RECOVERED = 'REDIS_RECOVERED';

    public const EVENT_POSTGRES_DOWN = 'POSTGRES_DOWN';

    public const EVENT_POSTGRES_RECOVERED = 'POSTGRES_RECOVERED';

    public const EVENT_QUEUE_CONGESTION = 'QUEUE_CONGESTION';

    public const EVENT_SYSTEM_ERROR = 'SYSTEM_ERROR';

    protected $fillable = [
        'delivery_id',
        'event_code',
        'status',
        'http_status',
        'rocketchat_message_id',
        'attempt_count',
        'attempted_at',
        'completed_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'attempt_count' => 'integer',
        'attempted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getFormattedAttemptedAtAttribute(): string
    {
        if (! $this->attempted_at) {
            return '--';
        }

        return $this->attempted_at->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
    }

    public function getErrorDetailsAttribute(): string
    {
        $status = strtoupper((string) $this->status);

        if ($status === 'SUCCESS') {
            return 'Gửi thành công sang Rocket.Chat'.($this->http_status ? " (HTTP {$this->http_status})" : '').'.';
        }

        if ($status === 'PENDING') {
            return 'Yêu cầu đang chờ gửi...';
        }

        if ($status === 'UNKNOWN') {
            return 'Không xác định được kết quả gửi (Hết thời gian chờ phản hồi từ spool audit).';
        }

        if (! empty($this->attributes['error_message'])) {
            return (string) $this->attributes['error_message'];
        }

        return match ((int) $this->http_status) {
            502 => 'Gửi thất bại (HTTP 502 - Bad Gateway): Máy chủ Rocket.Chat hoặc reverse proxy không phản hồi / bị ngắt kết nối.',
            500 => 'Gửi thất bại (HTTP 500 - Internal Server Error): Máy chủ Rocket.Chat gặp lỗi nội bộ khi xử lý yêu cầu.',
            503 => 'Gửi thất bại (HTTP 503 - Service Unavailable): Dịch vụ Rocket.Chat tạm thời quá tải hoặc đang bảo trì.',
            504 => 'Gửi thất bại (HTTP 504 - Gateway Timeout): Hết thời gian chờ phản hồi từ máy chủ Rocket.Chat.',
            401 => 'Gửi thất bại (HTTP 401 - Unauthorized): Sai X-Auth-Token hoặc X-User-Id trong cấu hình Rocket.Chat.',
            403 => 'Gửi thất bại (HTTP 403 - Forbidden): Tài khoản không có quyền đăng tin vào kênh chat này.',
            404 => 'Gửi thất bại (HTTP 404 - Not Found): Không tìm thấy endpoint hoặc kênh chat chỉ định.',
            400 => 'Gửi thất bại (HTTP 400 - Bad Request): Dữ liệu gửi sang Rocket.Chat không đúng định dạng.',
            0 => 'Gửi thất bại: Không thể kết nối tới máy chủ Rocket.Chat (Connection timeout hoặc mạng không khả dụng).',
            default => "Gửi thất bại (Mã phản hồi HTTP {$this->http_status}).",
        };
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_UNKNOWN,
        ];
    }

    public static function eventCodes(): array
    {
        return [
            self::EVENT_REDIS_DOWN,
            self::EVENT_REDIS_RECOVERED,
            self::EVENT_POSTGRES_DOWN,
            self::EVENT_POSTGRES_RECOVERED,
            self::EVENT_QUEUE_CONGESTION,
            self::EVENT_SYSTEM_ERROR,
        ];
    }
}
