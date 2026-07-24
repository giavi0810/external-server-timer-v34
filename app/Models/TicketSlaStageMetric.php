<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSlaStageMetric extends Model
{
    protected $fillable = [
        'ticket_sla_stage_id', 'metric_type', 'sla_goal_seconds',
        'used_before_seconds', 'used_at_checkpoint_seconds', 'effective_sla_seconds',
        'extra_time_granted_seconds', 'eligibility_status', 'old_due_at',
        'standard_due_at', 'adjusted_due_at', 'metric_result', 'result_reason',
        'overdue_at', 'overdue_owner_group_id',
    ];

    protected $casts = [
        'sla_goal_seconds' => 'integer',
        'used_before_seconds' => 'integer',
        'used_at_checkpoint_seconds' => 'integer',
        'effective_sla_seconds' => 'integer',
        'extra_time_granted_seconds' => 'integer',
        'old_due_at' => 'datetime',
        'standard_due_at' => 'datetime',
        'adjusted_due_at' => 'datetime',
        'overdue_at' => 'datetime',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TicketSlaStage::class, 'ticket_sla_stage_id');
    }

    public function overdueOwnerGroup(): BelongsTo
    {
        return $this->belongsTo(FreshdeskGroup::class, 'overdue_owner_group_id', 'group_id');
    }
}
