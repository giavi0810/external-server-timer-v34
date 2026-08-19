<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketStatusMetric extends Model
{
    protected $primaryKey = 'ticket_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'ticket_id',
        'resolution_total_seconds',
        'resolution_started_at',
        'waiting_total_seconds',
        'waiting_started_at',
        'pending_total_seconds',
        'pending_started_at',
        'end_total_seconds',
    ];

    protected $casts = [
        'resolution_total_seconds' => 'integer',
        'resolution_started_at' => 'datetime',
        'waiting_total_seconds' => 'integer',
        'waiting_started_at' => 'datetime',
        'pending_total_seconds' => 'integer',
        'pending_started_at' => 'datetime',
        'end_total_seconds' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }
}
