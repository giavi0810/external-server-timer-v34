<?php

namespace App\Services\Sla;

use App\Models\FreshdeskGroup;
use App\Models\Ticket;
use App\Models\TicketDueDateChange;
use App\Models\TicketHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class HistoryService
{
    private ?TimerService $timerService;

    public function __construct(?TimerService $timerService = null)
    {
        $this->timerService = $timerService;
    }

    public function buildTables(int $ticketId): array
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->first();
        if (!$ticket) {
            return [
                'priority' => ['ttr' => [], 'rt' => []],
                'group' => [],
                'due_date' => []
            ];
        }

        $priorityStages = $ticket->slaStages()
            ->whereNotNull('priority_stage_number')
            ->with('metrics')
            ->orderBy('sequence_number')
            ->get();

        return [
            'priority' => [
                'ttr' => $this->formatPriorityStages($ticket, $priorityStages, 'ttr'),
                'rt'  => $this->formatPriorityStages($ticket, $priorityStages, 'rt')
            ],
            'group' => $this->buildGroupTable($ticket),
            'due_date' => $this->buildDueDateTable($ticket)
        ];
    }

    /**
     * Reconstruct Priority History table (only stages where priority changes).
     */
    protected function formatPriorityStages(Ticket $ticket, $stages, string $type): array
    {
        return $stages->map(function ($stage) use ($ticket, $type): array {
            $metric = $stage->metrics->firstWhere('metric_type', $type);

            return [
                'stage' => $stage->priority_stage_number,
                'type_priority' => ($ticket->ticket_type ?? 'Ticket') . ' - ' . $stage->priority,
                'sla_priority' => $metric ? ($metric->sla_goal_seconds / 3600) . 'h' : '---',
                'used_total' => $metric ? $this->formatDuration((int) ($metric->used_at_checkpoint_seconds ?? $metric->used_before_seconds)) : '---',
                'extra_time' => $metric && $metric->extra_time_granted_seconds > 0
                    ? $this->formatDuration($metric->extra_time_granted_seconds)
                    : '---',
                'timestamp' => $stage->opened_at
                    ? Carbon::parse($stage->opened_at)->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')
                    : '---',
                'status' => $stage->checkpoint_at
                    ? ucfirst($metric?->metric_result ?? 'pending')
                    : '---',
            ];
        })->all();
    }

    /**
     * Reconstruct Group History table by parsing timeline from `c`, `g`, `s` keys.
     */
    protected function buildGroupTable(Ticket $ticket): array
    {
        $histories = TicketHistory::where('ticket_id', $ticket->ticket_id)
            ->whereIn('event_key', ['c', 'g', 's'])
            ->orderBy('occurred_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($histories->isEmpty()) {
            return [];
        }

        $table = [];
        $periodStart = null;
        $currentGroup = null;
        $currentStatus = 'Open';

        foreach ($histories as $history) {
            $key = $history->event_key;
            $val = $history->event_value;
            $time = $history->occurred_at;

            if ($key === 'c') {
                $periodStart = $time;
                continue;
            }

            if (!$periodStart) {
                $periodStart = $time;
            }

            if ($periodStart) {
                if ($key === 'g' && $currentGroup && strcasecmp($this->resolveGroupName($currentGroup), $this->resolveGroupName($val)) === 0) {
                    $currentGroup = $val;
                    continue;
                }

                $durationSec = abs($time->timestamp - $periodStart->timestamp);
                
                if ($durationSec > 0) {
                    $fromName = $currentGroup ? $this->resolveGroupName($currentGroup) : 'Unassigned';
                    $toName = $key === 'g' ? $this->resolveGroupName($val) : $fromName;

                    $eventType = 'Status Change';
                    $fromTo = "{$fromName} -> {$toName}";
                    
                    if ($key === 'g') {
                        $eventType = $currentGroup ? 'Update Group' : 'New assign';
                        $fromTo = $currentGroup ? "{$fromName} -> {$toName}" : "Unassigned -> {$toName}";
                    }

                    $fullStatus = $this->getFullStatus($currentStatus);
                    $table[] = [
                        'from_time' => $periodStart->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                        'to_time' => $time->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                        'duration' => $this->formatDuration((int)$durationSec),
                        'event' => $eventType,
                        'active_group' => $key === 'g' ? $toName : $fromName,
                        'from_to' => $fromTo,
                        'status' => $fullStatus,
                        'time_type' => $this->isPauseStatus($fullStatus) ? 'Pause status' : 'Active Status',
                        '_ts' => $periodStart->timestamp
                    ];
                }
            }

            if ($key === 'g') {
                $currentGroup = $val;
            } elseif ($key === 's') {
                $currentStatus = $val;
            }

            $periodStart = $time;
        }

        if ($periodStart) {
            $now = now();
            $durationSec = abs($now->timestamp - $periodStart->timestamp);
            $fullStatus = $this->getFullStatus($currentStatus);
            $activeGroupName = $currentGroup ? $this->resolveGroupName($currentGroup) : 'Unassigned';
            $table[] = [
                'from_time' => $periodStart->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                'to_time' => $now->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                'duration' => $this->formatDuration((int)$durationSec),
                'event' => 'Current Status',
                'active_group' => $activeGroupName,
                'from_to' => $activeGroupName . " -> Current",
                'status' => $fullStatus,
                'time_type' => $this->isPauseStatus($fullStatus) ? 'Pause status' : 'Active Status',
                '_ts' => $periodStart->timestamp
            ];
        }

        usort($table, fn($a, $b) => $a['_ts'] <=> $b['_ts']);
        
        return array_map(function($row) {
            unset($row['_ts']);
            return $row;
        }, $table);
    }

    protected function buildDueDateTable(Ticket $ticket): array
    {
        $histories = TicketDueDateChange::where('ticket_id', $ticket->ticket_id)
            ->with(['stage.metrics' => fn ($query) => $query->where('metric_type', 'ttr')])
            ->orderBy('change_number', 'asc')
            ->get();

        if ($histories->isEmpty()) {
            return [];
        }

        return $histories->map(function ($history) {
            $ttrMetric = $history->stage?->metrics->first();

            $reason = $history->reason_detail ?: $history->reason_code;
            if ($reason === 'unspecified' || empty($reason)) {
                $reason = '---';
            }

            $isStageClosed = (bool) $history->stage?->checkpoint_at;
            $status = $isStageClosed ? ucfirst($ttrMetric?->metric_result ?? 'pending') : '---';

            return [
                'change_no' => $history->change_number,
                'new_due_date' => $history->new_due_at
                    ? Carbon::parse($history->new_due_at)->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')
                    : '-',
                'phase' => $history->processing_phase ?? '-',
                'reason' => $reason,
                'timestamp' => $history->submitted_at
                    ? Carbon::parse($history->submitted_at)->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')
                    : '-',
                'agent' => $history->agent_name ?? '-',
                'status' => $status,
            ];
        })->all();
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '0s';
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        if ($secs > 0 || empty($parts)) $parts[] = "{$secs}s";
        
        return implode(' ', $parts);
    }

    private function resolveGroupName(string $groupId): string
    {
        if ($this->timerService) {
            return $this->timerService->resolveGroupName($groupId);
        }

        $fdGroup = FreshdeskGroup::where('group_id', $groupId)->first();
        return $fdGroup ? $fdGroup->name : (config("freshdesk.group_mapping.{$groupId}") ?? $groupId);
    }

    private function getFullStatus(string $status): string
    {
        if ($this->timerService) {
            return $this->timerService->getFullStatus($status);
        }

        return match (strtolower($status)) {
            'o', 'open' => 'Open',
            'p', 'pending' => 'Pending',
            'w', 'waiting for customer' => 'Waiting For Customer',
            'pr', 'processing' => 'Processing',
            'r', 'resolved' => 'Resolved',
            'c', 'closed' => 'Closed',
            default => $status,
        };
    }

    private function isPauseStatus(string $fullStatus): bool
    {
        if ($this->timerService) {
            return $this->timerService->isPauseStatus($fullStatus);
        }

        return in_array($fullStatus, config('freshdesk.pause_statuses', []), true);
    }
}
