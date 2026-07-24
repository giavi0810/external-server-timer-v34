<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketGroupMetric extends Model
{
    protected $fillable = [
        'ticket_id',
        'layer',
        'group_id',
        'total_seconds',
        'used_seconds',
        'started_at',
    ];

    protected $casts = [
        'total_seconds' => 'integer',
        'used_seconds' => 'integer',
        'started_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FreshdeskGroup::class, 'group_id', 'group_id');
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
