<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketFirstResponseMetric extends Model
{
    protected $primaryKey = 'ticket_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'ticket_id',
        'total_seconds',
        'used_seconds',
        'status',
        'started_at',
        'original_due_date_rt',
        'lastest_due_date_rt',
        'first_response_at',
        'agent_reply_count',
        'requester_reply_count',
        'agent_responded_at',
        'requester_responded_at',
    ];

    protected $casts = [
        'total_seconds' => 'integer',
        'used_seconds' => 'integer',
        'started_at' => 'datetime',
        'original_due_date_rt' => 'datetime',
        'lastest_due_date_rt' => 'datetime',
        'first_response_at' => 'datetime',
        'agent_reply_count' => 'integer',
        'requester_reply_count' => 'integer',
        'agent_responded_at' => 'datetime',
        'requester_responded_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function hasFirstResponse(): bool
    {
        return $this->first_response_at !== null;
    }

    public function remainingSeconds(): int
    {
        return max(0, $this->total_seconds - $this->used_seconds);
    }

    public function overdueSeconds(): int
    {
        return max(0, $this->used_seconds - $this->total_seconds);
    }

    public function isOverdue(): bool
    {
        return $this->used_seconds > $this->total_seconds;
    }
}
