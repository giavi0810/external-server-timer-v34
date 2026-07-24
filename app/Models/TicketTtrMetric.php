<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTtrMetric extends Model
{
    protected $primaryKey = 'ticket_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'ticket_id',
        'total_seconds',
        'used_seconds',
        'sla_mode',
        'started_at',
        'original_due_date_ttr',
        'lastest_due_date_ttr',
    ];

    protected $casts = [
        'total_seconds' => 'integer',
        'used_seconds' => 'integer',
        'started_at' => 'datetime',
        'original_due_date_ttr' => 'datetime',
        'lastest_due_date_ttr' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
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
