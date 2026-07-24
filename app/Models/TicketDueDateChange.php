<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketDueDateChange extends Model
{
    protected $fillable = [
        'ticket_id', 'ticket_sla_stage_id', 'change_number', 'old_due_at',
        'new_due_at', 'processing_phase', 'reason_code', 'reason_detail',
        'agent_id', 'agent_name', 'submitted_at',
    ];

    protected $casts = [
        'change_number' => 'integer',
        'old_due_at' => 'datetime',
        'new_due_at' => 'datetime',
        'agent_id' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TicketSlaStage::class, 'ticket_sla_stage_id');
    }
}
