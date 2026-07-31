<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketEvent extends Model
{
    public const EVENT_TICKET_CREATED = 'ticket_created';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_PRIORITY_CHANGED = 'priority_changed';
    public const EVENT_GROUP_CHANGED = 'group_changed';
    public const EVENT_AGENT_REPLIED = 'agent_replied';
    public const EVENT_REQUESTER_REPLIED = 'requester_replied';
    public const EVENT_DUE_DATE_CHANGED = 'due_date_changed';
    public const EVENT_TICKET_REOPENED = 'ticket_reopened';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    public const SUPPORTED_EVENT_TYPES = [
        self::EVENT_TICKET_CREATED,
        self::EVENT_STATUS_CHANGED,
        self::EVENT_PRIORITY_CHANGED,
        self::EVENT_GROUP_CHANGED,
        self::EVENT_AGENT_REPLIED,
        self::EVENT_REQUESTER_REPLIED,
        self::EVENT_DUE_DATE_CHANGED,
        self::EVENT_TICKET_REOPENED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'idempotency_key',
        'event_type',
        'event_data',
        'field_changes',
        'status',
        'attempt_count',
        'last_error',
        'event_timestamp',
        'received_at',
        'locked_at',
        'processed_at',
        'logic_generation',
        'source_order_key',
        'processing_token',
    ];

    protected $casts = [
        'event_data' => 'array',
        'field_changes' => 'array',
        'event_timestamp' => 'datetime',
        'received_at' => 'datetime',
        'locked_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempt_count' => 'integer',
        'logic_generation' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TicketHistory::class, 'ticket_event_id');
    }

    public function markAsQueued(): void
    {
        $this->update(['status' => self::STATUS_QUEUED]);
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'locked_at' => now(),
            'attempt_count' => $this->attempt_count + 1,
        ]);
    }

    public function markAsPending(): void
    {
        $this->update(['status' => self::STATUS_PENDING, 'locked_at' => null]);
    }

    public function markAsProcessed(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'locked_at' => null,
            'processed_at' => now(),
            'last_error' => null,
            'processing_token' => null,
        ]);
    }

    public function markAsFailed(?string $error = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'locked_at' => null,
            'last_error' => $error,
            'processing_token' => null,
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function getTicketData(): array
    {
        return $this->event_data['ticket_data'] ?? [];
    }

    public function getFieldChanges(): array
    {
        return $this->field_changes ?? [];
    }

    public static function isSupportedType(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED_EVENT_TYPES, true);
    }

    public static function makeIdempotencyKey(
        int $ticketId,
        string $eventType,
        mixed $eventTimestamp,
        mixed $actor = 'none'
    ): string {
        return hash('sha256', json_encode([
            'ticket_id' => $ticketId,
            'event_type' => $eventType,
            'event_timestamp' => \Carbon\Carbon::parse($eventTimestamp)->toIso8601String(),
            'actor' => (string) $actor,
        ], JSON_THROW_ON_ERROR));
    }
}
