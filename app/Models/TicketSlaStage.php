<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TicketSlaStage extends Model
{
    protected $fillable = [
        'ticket_id', 'sla_policy_id', 'sequence_number', 'priority_stage_number',
        'trigger_type', 'priority', 'sla_mode', 'opened_at', 'checkpoint_at',
        'opened_by_event_id', 'checkpoint_event_id',
    ];

    protected $casts = [
        'sequence_number' => 'integer',
        'priority_stage_number' => 'integer',
        'opened_at' => 'datetime',
        'checkpoint_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(TicketSlaStageMetric::class);
    }

    public function dueDateChange(): HasOne
    {
        return $this->hasOne(TicketDueDateChange::class);
    }

    public function openedByEvent(): BelongsTo
    {
        return $this->belongsTo(TicketEvent::class, 'opened_by_event_id');
    }

    public function checkpointEvent(): BelongsTo
    {
        return $this->belongsTo(TicketEvent::class, 'checkpoint_event_id');
    }
}
