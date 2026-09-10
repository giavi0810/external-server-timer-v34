<?php

namespace App\Models;

use Carbon\CarbonInterface;
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

    /**
     * Return the Group used time at a shared runtime checkpoint.
     *
     * The persisted value is expressed in seconds. When the timer is active,
     * the open interval from started_at to the checkpoint is included without
     * mutating the metric.
     */
    public function effectiveUsedSeconds(?CarbonInterface $at = null): int
    {
        $usedSeconds = max(0, (int) $this->used_seconds);

        if (! $this->started_at) {
            return $usedSeconds;
        }

        $checkpoint = $at ?? now();
        $activeSeconds = max(
            0,
            $checkpoint->getTimestamp() - $this->started_at->getTimestamp()
        );

        return $usedSeconds + $activeSeconds;
    }

    /**
     * BR-GRP-04 / CAL-GROUP-CONTRIBUTION.
     *
     * Calculate the Group contribution as a percentage of the Ticket TTR SLA.
     * Both the Group used time and TTR total are expressed in seconds. The
     * result is calculated at runtime and is intentionally not persisted.
     */
    public function contributionRatio(
        int $ttrTotalSeconds,
        ?CarbonInterface $at = null
    ): float {
        if ($ttrTotalSeconds <= 0) {
            return 0.0;
        }

        return round(
            ($this->effectiveUsedSeconds($at) / $ttrTotalSeconds) * 100,
            2
        );
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
