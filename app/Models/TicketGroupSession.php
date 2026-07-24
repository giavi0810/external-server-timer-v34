<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketGroupSession extends Model
{
    protected $fillable = [
        'ticket_id', 'group_id', 'layer', 'status', 'from_time', 'to_time',
        'opened_by_event_id', 'closed_by_event_id',
    ];

    protected $casts = ['from_time' => 'datetime', 'to_time' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FreshdeskGroup::class, 'group_id', 'group_id');
    }

    public function openedByEvent(): BelongsTo
    {
        return $this->belongsTo(TicketEvent::class, 'opened_by_event_id');
    }

    public function closedByEvent(): BelongsTo
    {
        return $this->belongsTo(TicketEvent::class, 'closed_by_event_id');
    }
}
