<?php

namespace App\Services\Sla;

use App\Models\Ticket;
use App\Models\SlaPolicy;
use App\Models\TicketGroupMetric;
use App\Services\FreshdeskApiService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SlaInitializationService — Khởi tạo & phân bổ SLA cho Ticket.
 */
class SlaInitializationService
{
    protected TimerService $timerService;
    protected FreshdeskApiService $freshdeskService;

    public function __construct(TimerService $timerService, FreshdeskApiService $freshdeskService)
    {
        $this->timerService = $timerService;
        $this->freshdeskService = $freshdeskService;
    }

    /**
     * Lazy Init: Tự động khởi tạo SLA nếu Ticket chưa có dữ liệu.
     */
    public function ensureSlaInitialized(Ticket $ticket, ?Carbon $at = null): void
    {
        if ($ticket->getOrCreateTtrMetric()->total_seconds === 0) {
            $this->initializeSlaValues($ticket);

            $groupLayer = $this->timerService->getGroupLayer($ticket->group_id);
            if ($groupLayer) {
                $timer = $ticket->getOrCreateGroupMetric(
                    $groupLayer,
                    $ticket->group_id
                );
                $aggregateTimer = $ticket->getOrCreateGroupMetric($groupLayer, null);
                if (!$aggregateTimer->started_at && $this->timerService->isRunStatus($ticket->status)) {
                    $this->timerService->startGroupTimer($ticket, $groupLayer, $at);
                }
            }

            $ticket->save();
        }
    }

    /**
     * Khởi tạo SLA values từ bảng sla_policies.
     */
    public function initializeSlaValues(Ticket $ticket): void
    {
        $config = SlaPolicy::getPolicy((string) $ticket->ticket_type, (string) $ticket->priority);

        if (!$config) {
            Log::warning("Không tìm thấy SLA config", [
                'ticket_type' => $ticket->ticket_type,
                'priority'    => $ticket->priority,
            ]);
            return;
        }

        $createdAt = $ttrDue = $rtDue = null;
        if ($ticket->fd_created_at) {
            $createdAt = Carbon::parse($ticket->fd_created_at);
            
            $ttrDue = (clone $createdAt)->addSeconds($config->total_seconds);
            $rtDue = (clone $createdAt)->addSeconds($config->rt_seconds);
        }

        $ttrMetric = $ticket->getOrCreateTtrMetric();
        $ttrMetric->update([
            'total_seconds' => $config->total_seconds,
            'used_seconds' => 0,
            'processing_mode' => 'priority-driven',
            'started_at' => $createdAt ?? null,
            'original_due_date_ttr' => $ttrDue ?? null,
            'latest_due_date_ttr' => $ttrDue ?? null,
        ]);

        $rtTotal = $config->rt_seconds;
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();
        $rtMetric->update([
            'total_seconds' => $rtTotal,
            'used_seconds' => 0,
            'status' => 'running',
            'started_at' => $createdAt ?? null,
            'original_due_date_rt' => $rtDue ?? null,
            'latest_due_date_rt' => $rtDue ?? null,
        ]);

        $initialGroupId = $ticket->group_id;
        foreach (['L1' => $config->l1_seconds, 'L2' => $config->l2_seconds, 'L3' => $config->l3_seconds, 'L4' => $config->l4_seconds] as $layer => $seconds) {
            TicketGroupMetric::updateOrCreate(
                ['ticket_id' => $ticket->ticket_id, 'layer' => $layer, 'group_id' => null],
                ['total_seconds' => $seconds, 'used_seconds' => 0]
            );

            if ($this->timerService->getGroupLayer($ticket->group_id) === $layer && $initialGroupId) {
                TicketGroupMetric::updateOrCreate(
                    ['ticket_id' => $ticket->ticket_id, 'layer' => $layer, 'group_id' => $initialGroupId],
                    ['total_seconds' => $seconds, 'used_seconds' => 0]
                );
            }
        }

        $statusMetric = $ticket->getOrCreateStatusMetric();
        $statusMetric->update([
            'resolution_total_seconds' => 0,
            'resolution_started_at' => $createdAt ?? null,
            'waiting_total_seconds' => 0,
            'pending_total_seconds' => 0,
            'end_total_seconds' => 0,
        ]);

        Log::info("SLA khởi tạo từ config", [
            'ticket_id'  => $ticket->ticket_id,
            'ttr_total'  => $config->total_seconds,
            'rt_total'   => $config->rt_seconds,
        ]);
    }

    public function normalizePriority($priority): string
    {
        $map = [
            1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Urgent',
            '1' => 'Low', '2' => 'Medium', '3' => 'High', '4' => 'Urgent',
        ];
        return $map[$priority] ?? (is_string($priority) ? $priority : 'Low');
    }

    public function resolveGroupName($groupId): ?string
    {
        return $this->freshdeskService->resolveGroupName($groupId);
    }
}
