<?php

namespace App\Services\Sla;

use App\Models\FreshdeskGroup;
use App\Models\Ticket;
use App\Models\SlaPolicy;
use App\Models\TicketGroupMetric;
use App\Models\TicketFirstResponseMetric;
use App\Models\TicketStatusMetric;
use App\Models\TicketEvent;
use App\Models\TicketGroupSession;
use App\Services\FreshdeskStatusNormalizer;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * TimerService — Logic timer dùng chung cho tất cả handlers.
 */
class TimerService
{
    protected const TRACKED_GROUP_LAYERS = ['L1', 'L2', 'L3', 'L4'];

    public function __construct(
        private readonly FreshdeskStatusNormalizer $statusNormalizer
    ) {
    }

    public function startGroupTimer(Ticket $ticket, string $layer, ?Carbon $at = null): void
    {
        if (!in_array($layer, self::TRACKED_GROUP_LAYERS, true)) {
            return;
        }

        $now = $at ?? now();

        $groupId = $ticket->group_id;
        if ($groupId) {
            $timer = $ticket->getOrCreateGroupMetric($layer, $groupId);
            if (!$timer->started_at) {
                $timer->started_at = $now;
                $timer->save();
            }
        }

        $aggregateTimer = $ticket->getOrCreateGroupMetric($layer, null);
        if (!$aggregateTimer->started_at) {
            $aggregateTimer->started_at = $now;
            $aggregateTimer->save();
        }

        if ($groupId && !TicketGroupSession::where('ticket_id', $ticket->ticket_id)->whereNull('to_time')->exists()) {
            $sourceEvent = $this->sourceEventAt($ticket, $now);
            if ($sourceEvent) {
                TicketGroupSession::create([
                    'ticket_id' => $ticket->ticket_id,
                    'group_id' => $groupId,
                    'layer' => $layer,
                    'status' => $ticket->status,
                    'from_time' => $now,
                    'opened_by_event_id' => $sourceEvent->id,
                ]);
            }
        }
    }

    public function stopGroupTimer(Ticket $ticket, string $layer, ?Carbon $at = null): void
    {
        $now = $at ?? now();

        $groupId = $ticket->group_id;
        if ($groupId) {
            $timer = $ticket->getOrCreateGroupMetric($layer, $groupId);
            if ($timer->started_at) {
                $startedAt = Carbon::parse($timer->started_at);
                $elapsed = $startedAt->diffInSeconds($now, false);

                $timer->used_seconds = max(0, (int)$timer->used_seconds + max(0, $elapsed));
                $timer->started_at = null;
                $timer->save();
            }
        }

        $aggregateTimer = $ticket->getOrCreateGroupMetric($layer, null);
        if ($aggregateTimer->started_at) {
            $startedAt = Carbon::parse($aggregateTimer->started_at);
            $elapsed = $startedAt->diffInSeconds($now, false);
            $aggregateTimer->used_seconds = max(0, (int) $aggregateTimer->used_seconds + max(0, $elapsed));
            $aggregateTimer->started_at = null;
            $aggregateTimer->save();
        }

        $this->closeOpenGroupSession($ticket, $now);
    }

    public function accumulateGroupUsedTime(Ticket $ticket, Carbon $now): void
    {
        $activeTimers = $ticket->groupMetrics()
            ->whereNotNull('started_at')
            ->get();

        foreach ($activeTimers as $timer) {
            $duration = Carbon::parse($timer->started_at)->diffInSeconds($now, false);
            $timer->used_seconds = max(0, (int)$timer->used_seconds + max(0, $duration));
            $timer->started_at = $now;
            $timer->save();
        }

        $this->closeOpenGroupSession($ticket, $now);
    }

    protected function sourceEventAt(Ticket $ticket, Carbon $at): ?TicketEvent
    {
        return TicketEvent::where('ticket_id', $ticket->ticket_id)
            ->where('event_timestamp', '<=', $at)
            ->latest('event_timestamp')
            ->latest('id')
            ->first();
    }

    protected function closeOpenGroupSession(Ticket $ticket, Carbon $at): void
    {
        $session = TicketGroupSession::where('ticket_id', $ticket->ticket_id)
            ->whereNull('to_time')
            ->latest('id')
            ->first();
        if (!$session) {
            return;
        }

        $sourceEvent = $this->sourceEventAt($ticket, $at);
        if (!$sourceEvent) {
            return;
        }

        $session->update([
            'to_time' => $at,
            'closed_by_event_id' => $sourceEvent->id,
        ]);
    }

    public function stopAllActiveGroupTimers(Ticket $ticket, Carbon $now): void
    {
        $activeTimers = $ticket->groupMetrics()
            ->whereNotNull('started_at')
            ->get();

        foreach ($activeTimers as $timer) {
            $startedAt = Carbon::parse($timer->started_at);
            $duration = $startedAt->diffInSeconds($now, false);

            $timer->used_seconds = max(0, (int)$timer->used_seconds + max(0, $duration));
            $timer->started_at = null;
            $timer->save();
        }
    }

    public function recalculateGroupMetrics(Ticket $ticket, array $layerBudgetOverrides = [], ?Carbon $now = null): void
    {
        $layerConfig = SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $ticket->priority);

        if (!$layerConfig) return;

        $normalizedOverrides = [];
        foreach ($layerBudgetOverrides as $layer => $seconds) {
            $normalizedLayer = strtoupper((string) $layer);
            if (!in_array($normalizedLayer, self::TRACKED_GROUP_LAYERS, true)) {
                continue;
            }
            $normalizedOverrides[$normalizedLayer] = max(0, (int) $seconds);
        }

        $timers = $ticket->groupMetrics()->get();
        foreach ($timers as $timer) {
            if (!in_array($timer->layer, self::TRACKED_GROUP_LAYERS, true)) {
                continue;
            }

            $budgetField = strtolower($timer->layer) . '_seconds';
            $budget = $normalizedOverrides[$timer->layer] ?? ($layerConfig->$budgetField ?? 0);
            $safeUsed = max(0, (int)($timer->used_seconds ?? 0));

            if ($timer->group_id === null) continue;

            $timer->total_seconds     = $budget;
            $timer->used_seconds      = $safeUsed;
            $timer->save();
        }

        foreach (self::TRACKED_GROUP_LAYERS as $layer) {
            $aggregateTimer = $ticket->getOrCreateGroupMetric($layer, null);
            $totalUsed = max(0, (int) ($aggregateTimer->used_seconds ?? 0));

            $budgetField = strtolower($layer) . '_seconds';
            $layerBudget = $normalizedOverrides[$layer] ?? ($layerConfig ? $layerConfig->$budgetField : 0);

            $aggregateTimer->total_seconds     = $layerBudget;
            $aggregateTimer->used_seconds      = $totalUsed;
            $aggregateTimer->save();
        }

        $statusMetric = $ticket->getOrCreateStatusMetric();
        $this->recalculateTtrMetrics($ticket, $statusMetric, $now ?? now());
    }

    public function accumulateRtUsedTime(TicketFirstResponseMetric $rtMetric, Carbon $now): void
    {
        if ($rtMetric->started_at) {
            $startedAt = Carbon::parse($rtMetric->started_at);
            $elapsed = $now->timestamp - $startedAt->timestamp;
            $rtMetric->used_seconds += max(0, $elapsed);
            $rtMetric->started_at = null;
        }
    }

    public function finalizeRtUsedTime(TicketFirstResponseMetric $rtMetric, Carbon $now): void
    {
        if ($rtMetric->started_at) {
            $startedAt = Carbon::parse($rtMetric->started_at);
            $elapsed = $now->timestamp - $startedAt->timestamp;
            $rtMetric->used_seconds += max(0, $elapsed);
            $rtMetric->started_at = null;
        }
    }

    public function recalculateRtMetrics(TicketFirstResponseMetric $rtMetric): void
    {
    }

    public function recalculateTtrMetrics(Ticket $ticket, TicketStatusMetric $statusMetric, Carbon $now): void
    {
        $ttrMetric = $ticket->getOrCreateTtrMetric();

        // TTR is immutable after the first Resolved/Closed checkpoint.
        if ($ticket->resolved_at || $ticket->closed_at) {
            return;
        }

        $ttrMetric->used_seconds = $this->calculateTtrUsedSeconds($ticket, $statusMetric, $now);
        $ttrMetric->save();
    }

    public function finalizeTtrUsedTime(Ticket $ticket, TicketStatusMetric $statusMetric, Carbon $endedAt): void
    {
        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $ttrMetric->used_seconds = $this->calculateTtrUsedSeconds($ticket, $statusMetric, $endedAt);
        $ttrMetric->started_at = null;
        $ttrMetric->save();
    }

    protected function calculateTtrUsedSeconds(
        Ticket $ticket,
        TicketStatusMetric $statusMetric,
        Carbon $checkpointAt
    ): int {
        if (!$ticket->fd_created_at) {
            return 0;
        }

        $createdAt = Carbon::parse($ticket->fd_created_at);
        $elapsed = max(0, $checkpointAt->timestamp - $createdAt->timestamp);
        $ttrMetric = $ticket->getOrCreateTtrMetric();

        if ($ttrMetric->processing_mode === 'due-driven') {
            return $elapsed;
        }

        $excluded = max(0, (int) $statusMetric->waiting_total_seconds)
            + max(0, (int) $statusMetric->pending_total_seconds)
            + max(0, (int) $statusMetric->end_total_seconds);

        if ($statusMetric->waiting_started_at) {
            $excluded += max(0, $checkpointAt->timestamp - Carbon::parse($statusMetric->waiting_started_at)->timestamp);
        }
        if ($statusMetric->pending_started_at) {
            $excluded += max(0, $checkpointAt->timestamp - Carbon::parse($statusMetric->pending_started_at)->timestamp);
        }
        return max(0, $elapsed - $excluded);
    }

    public function accumulateWaitingTime(TicketStatusMetric $statusMetric, mixed $fromStatus, Carbon $now): void
    {
        $fromStatus = $this->canonicalizeStatus($fromStatus);

        if ($fromStatus === 'Waiting For Customer' && $statusMetric->waiting_started_at) {
            $startedAt = Carbon::parse($statusMetric->waiting_started_at);
            $duration = $now->timestamp - $startedAt->timestamp;
            $statusMetric->waiting_total_seconds += max(0, $duration);
            $statusMetric->waiting_started_at = null;
        } elseif ($fromStatus === 'Pending' && $statusMetric->pending_started_at) {
            $startedAt = Carbon::parse($statusMetric->pending_started_at);
            $duration = $now->timestamp - $startedAt->timestamp;
            $statusMetric->pending_total_seconds += max(0, $duration);
            $statusMetric->pending_started_at = null;
        }
    }

    public function getLastWaitingDuration(TicketStatusMetric $statusMetric, mixed $fromStatus, Carbon $now): int
    {
        $fromStatus = $this->canonicalizeStatus($fromStatus);

        if ($fromStatus === 'Waiting For Customer' && $statusMetric->waiting_started_at) {
            return $now->timestamp - Carbon::parse($statusMetric->waiting_started_at)->timestamp;
        } elseif ($fromStatus === 'Pending' && $statusMetric->pending_started_at) {
            return $now->timestamp - Carbon::parse($statusMetric->pending_started_at)->timestamp;
        }
        return 0;
    }

    public function finalizeResolutionTime(Ticket $ticket, TicketStatusMetric $statusMetric): void
    {
        $endpoint = $ticket->closed_at ?: $ticket->resolved_at;
        if (!$endpoint || !$ticket->fd_created_at) {
            return;
        }

        $createdAt = Carbon::parse($ticket->fd_created_at);
        $endedAt = Carbon::parse($endpoint);
        $statusMetric->resolution_total_seconds = max(0, $endedAt->timestamp - $createdAt->timestamp);
        $statusMetric->resolution_started_at = null;
    }

    public function canonicalizeStatus(mixed $status): ?string
    {
        return $this->statusNormalizer->canonicalize($status);
    }

    public function isRunStatus(mixed $status): bool
    {
        return in_array($this->canonicalizeStatus($status), config('freshdesk.run_statuses', []), true);
    }

    public function isPauseStatus(mixed $status): bool
    {
        return in_array($this->canonicalizeStatus($status), config('freshdesk.pause_statuses', []), true);
    }

    public function isEndStatus(mixed $status): bool
    {
        return in_array($this->canonicalizeStatus($status), config('freshdesk.end_statuses', []), true);
    }

    public function getShortStatus(mixed $statusName): string
    {
        $statusName = $this->canonicalizeStatus($statusName) ?? '';

        $map = [
            'Open' => 'O',
            'Pending' => 'P',
            'Resolved' => 'R',
            'Closed' => 'C',
            'Waiting For Customer' => 'Wfc',
            'Processing' => 'Pr',
        ];

        return $map[$statusName] ?? $statusName;
    }

    public function getFullStatus(string $shortStatus): string
    {
        $map = [
            'O' => 'Open',
            'P' => 'Pending',
            'R' => 'Resolved',
            'C' => 'Closed',
            'Wfc' => 'Waiting For Customer',
            'Pr' => 'Processing',
        ];

        return $map[$shortStatus] ?? $shortStatus;
    }

    public function getGroupLayer(?string $groupId, ?string $groupName = null): ?string
    {
        $group = null;
        $resolvedName = $groupName;

        if ($groupId) {
            $group = FreshdeskGroup::where('group_id', (string) $groupId)->first();
            $resolvedName = $resolvedName
                ?: $group?->name
                ?: config("freshdesk.group_mapping.{$groupId}");

            if ($resolvedName) {
                $detectedFromId = $this->detectMainLayerFromName($resolvedName);
                if ($detectedFromId) {
                    if ($group && $group->main_layer !== $detectedFromId) {
                        $group->main_layer = $detectedFromId;
                        $group->save();
                    }

                    return $detectedFromId;
                }
            }

            if ($group && $group->main_layer && in_array($group->main_layer, ['L1', 'L2', 'L3', 'L4'], true)) {
                return $group->main_layer;
            }
        }

        if (!$resolvedName) {
            return null;
        }

        $detected = $this->detectMainLayerFromName($resolvedName);
        if ($detected) {
            $group = $group ?: FreshdeskGroup::where('name', $resolvedName)->first();
            if ($group && $group->main_layer !== $detected) {
                $group->main_layer = $detected;
                $group->save();
            }
            return $detected;
        }

        $group = $group ?: FreshdeskGroup::where('name', $resolvedName)->first();
        if ($group && $group->main_layer && in_array($group->main_layer, ['L1', 'L2', 'L3', 'L4'], true)) {
            return $group->main_layer;
        }

        return 'L1';
    }

    public function resolveGroupName(?string $groupId): ?string
    {
        if (!$groupId) {
            return null;
        }

        $group = FreshdeskGroup::where('group_id', (string) $groupId)->first();
        if ($group && $group->name) {
            return $group->name;
        }

        $mappedName = config("freshdesk.group_mapping.{$groupId}");
        if (is_string($mappedName) && $mappedName !== '') {
            return $mappedName;
        }

        return "Group {$groupId}";
    }

    public function detectMainLayerFromName(string $groupName): ?string
    {
        $name = strtolower($groupName);

        if (str_contains($name, 'l1') || str_contains($name, 'layer 1')) return 'L1';
        if (str_contains($name, 'l2') || str_contains($name, 'layer 2')) return 'L2';
        if (str_contains($name, 'l3') || str_contains($name, 'layer 3')) return 'L3';
        if (str_contains($name, 'l4') || str_contains($name, 'layer 4')) return 'L4';

        return null;
    }

    public function resolveGroupIdByGroupName(?string $groupName): ?string
    {
        if (!$groupName) {
            return null;
        }

        $group = FreshdeskGroup::where('name', $groupName)->first();

        if ($group) {
            return $group->group_id;
        }

        foreach (config('freshdesk.group_mapping', []) as $mappedGroupId => $mappedGroupName) {
            if (strcasecmp((string) $mappedGroupName, (string) $groupName) === 0) {
                return (string) $mappedGroupId;
            }
        }

        $fallbackGroupId = $this->extractGroupIdFromFallbackName($groupName);
        if ($fallbackGroupId) {
            $groupById = FreshdeskGroup::where('group_id', $fallbackGroupId)->first();
            return $groupById ? $groupById->group_id : $fallbackGroupId;
        }

        return null;
    }

    protected function extractGroupIdFromFallbackName(?string $groupName): ?string
    {
        if (!is_string($groupName) || trim($groupName) === '') {
            return null;
        }

        if (preg_match('/^Group\s+([A-Za-z0-9_-]+)$/i', trim($groupName), $matches)) {
            return (string) $matches[1];
        }

        return null;
    }
}
