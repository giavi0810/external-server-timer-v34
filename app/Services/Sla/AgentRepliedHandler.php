<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * AgentRepliedHandler — Xử lý sự kiện Agent phản hồi lần đầu.
 */
class AgentRepliedHandler
{
    protected TimerService $timerService;
    protected TimelineService $timelineService;
    protected FreshdeskApiService $freshdeskService;

    public function __construct(
        TimerService $timerService,
        TimelineService $timelineService,
        ?FreshdeskApiService $freshdeskService = null
    ) {
        $this->timerService = $timerService;
        $this->timelineService = $timelineService;
        $this->freshdeskService = $freshdeskService ?? app(FreshdeskApiService::class);
    }

    /**
     * Xử lý sự kiện agent_replied.
     */
    public function handle(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();
        $rtMetric = $ticket->getOrCreateFirstResponseMetric();

        $eventData = $event->event_data ?? [];
        $convData = $eventData['conversation_data'] ?? [];
        $now = $event->occurredAt();
        
        $actorId = $convData['actor_id'] ?? null;
        $actorLabel = $actorId ? (string)$actorId : null;

        $actorType = $convData['actor_type'] ?? null;
        if ($actorType && !in_array($actorType, ['agent'])) {
            Log::info("AgentRepliedHandler: Bỏ qua chốt RT vì actor_type không phải agent", [
                'ticket_id' => $ticketId,
                'actor_type' => $actorType,
            ]);
            return;
        }

        if (!$rtMetric->hasFirstResponse()
            && !in_array($rtMetric->status, ['ended_replied', 'ended_closed_no_reply'], true)) {
            $rtMetric->first_response_at = $now;

            $this->timerService->finalizeRtUsedTime($rtMetric, $now);

            $rtMetric->status = 'ended_replied';
            $this->timerService->recalculateRtMetrics($rtMetric);

            $rtMetric->save();

            $this->timelineService->appendTicketEventLog($ticket, 'fr', $now->format('Y-m-d\TH:i:s\Z'), $event->event_timestamp, null, $event);
            
            Log::info("AgentRepliedHandler: RT ended by Agent replied", [
                'ticket_id' => $ticketId,
                'rt_used' => $rtMetric->used_seconds,
            ]);

            // Tuân thủ CAL-RT-RESULT (BR-STA-03, BR-EVL-01): Đánh giá kết quả RT cho Stage Metric của Stage đang mở
            $activeStage = $ticket->slaStages()
                ->whereNull('checkpoint_at')
                ->latest('sequence_number')
                ->first();

            $stageRtMetric = $activeStage?->metrics()->where('metric_type', 'rt')->first();

            if ($stageRtMetric && !in_array($stageRtMetric->metric_result, ['fail', 'not_applicable'], true)) {
                $effectiveSla = (int) ($stageRtMetric->effective_sla_seconds > 0
                    ? $stageRtMetric->effective_sla_seconds
                    : ($stageRtMetric->sla_goal_seconds > 0 ? $stageRtMetric->sla_goal_seconds : $rtMetric->total_seconds));
                $dueAt = $stageRtMetric->adjusted_due_at ?? $rtMetric->latest_due_date_rt;

                $usedSeconds = (int) $rtMetric->used_seconds;
                $exceededSla = $effectiveSla > 0 && $usedSeconds > $effectiveSla;
                $exceededDue = $dueAt && $now->greaterThan(Carbon::parse($dueAt));

                $failed = $exceededSla || $exceededDue;
                $overdueAt = $dueAt ? Carbon::parse($dueAt) : $now;

                $stageRtMetric->update([
                    'used_at_checkpoint_seconds' => $usedSeconds,
                    'metric_result' => $failed ? 'fail' : 'pass',
                    'result_reason' => 'agent_replied_' . ($failed ? 'after_due' : 'before_due'),
                    'overdue_at' => $failed ? $overdueAt : null,
                    'overdue_owner_group_id' => $failed ? $ticket->group_id : null,
                ]);

                if ($failed) {
                    $this->freshdeskService->addTagToTicket($ticket->ticket_id, 'has_stage_fail_SLA');
                }
            }
        }
        
        if ($actorLabel) {
            $this->timelineService->appendTicketEventLog($ticket, 'a', 'rep', $now->format('Y-m-d\TH:i:s\Z'), $actorLabel, $event);
        } else {
            $this->timelineService->appendTicketEventLog($ticket, 'a', 'rep', $now->format('Y-m-d\TH:i:s\Z'), null, $event);
        }
    }
}
