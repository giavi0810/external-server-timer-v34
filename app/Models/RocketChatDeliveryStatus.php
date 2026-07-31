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
