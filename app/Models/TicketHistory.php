<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketHistory extends Model
{
    protected $fillable = [
        'ticket_id', 'ticket_event_id', 'event_key', 'event_value',
        'label', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(TicketEvent::class, 'ticket_event_id');
    }
}
