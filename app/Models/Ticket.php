<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    protected $primaryKey = 'ticket_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'ticket_id', 'source_ticket_id', 'creation_reason', 'subject', 'status',
        'priority', 'ticket_type', 'group_id', 'requester_id', 'fd_created_at',
        'resolved_at', 'closed_at', 'reopened_at',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'source_ticket_id' => 'integer',
        'requester_id' => 'integer',
        'fd_created_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function sourceTicket(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_ticket_id', 'ticket_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class, 'ticket_id', 'ticket_id');
    }

    public function ttrMetric(): HasOne
    {
        return $this->hasOne(TicketTtrMetric::class, 'ticket_id', 'ticket_id');
    }

    public function firstResponseMetric(): HasOne
    {
        return $this->hasOne(TicketFirstResponseMetric::class, 'ticket_id', 'ticket_id');
    }

    public function statusMetric(): HasOne
    {
        return $this->hasOne(TicketStatusMetric::class, 'ticket_id', 'ticket_id');
    }

    public function groupMetrics(): HasMany
    {
        return $this->hasMany(TicketGroupMetric::class, 'ticket_id', 'ticket_id');
    }

    public function groupSessions(): HasMany
    {
        return $this->hasMany(TicketGroupSession::class, 'ticket_id', 'ticket_id');
    }

    public function slaStages(): HasMany
    {
        return $this->hasMany(TicketSlaStage::class, 'ticket_id', 'ticket_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TicketHistory::class, 'ticket_id', 'ticket_id');
    }

    public function dueDateChanges(): HasMany
    {
        return $this->hasMany(TicketDueDateChange::class, 'ticket_id', 'ticket_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FreshdeskGroup::class, 'group_id', 'group_id');
    }

    public function getOrCreateTtrMetric(): TicketTtrMetric
    {
        return TicketTtrMetric::firstOrCreate(
            ['ticket_id' => $this->ticket_id],
            ['total_seconds' => 0, 'used_seconds' => 0, 'sla_mode' => 'priority-driven']
        );
    }

    public function getOrCreateFirstResponseMetric(): TicketFirstResponseMetric
    {
        return TicketFirstResponseMetric::firstOrCreate(
            ['ticket_id' => $this->ticket_id],
            ['total_seconds' => 0, 'used_seconds' => 0, 'status' => 'running']
        );
    }

    public function getOrCreateStatusMetric(): TicketStatusMetric
    {
        return TicketStatusMetric::firstOrCreate(['ticket_id' => $this->ticket_id]);
    }

    public function getOrCreateGroupMetric(string $layer, ?string $groupId = null): TicketGroupMetric
    {
        if ($groupId !== null) {
            $existing = TicketGroupMetric::where('ticket_id', $this->ticket_id)
                ->where('group_id', $groupId)
                ->first();

            if ($existing) {
                if ($existing->layer !== $layer) {
                    $existing->layer = $layer;
                    $existing->save();
                }
                return $existing;
            }
        }

        return TicketGroupMetric::firstOrCreate(
            ['ticket_id' => $this->ticket_id, 'layer' => $layer, 'group_id' => $groupId],
            ['total_seconds' => 0, 'used_seconds' => 0]
        );
    }

    public function getCurrentGroupLayer(): ?string
    {
        if (!$this->group_id) {
            return null;
        }

        $group = FreshdeskGroup::find((string) $this->group_id);

        return $group?->main_layer
            ?? config("freshdesk.group_layers.{$group?->name}");
    }

    public function isPaused(): bool
    {
        return in_array($this->status, config('freshdesk.pause_statuses', []), true);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, config('freshdesk.run_statuses', []), true);
    }

    public function isEnded(): bool
    {
        return in_array($this->status, config('freshdesk.end_statuses', []), true);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', config('freshdesk.end_statuses', []));
    }
}
