<?php

namespace App\Services\Sla;

use App\Models\TicketEvent;
use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use Illuminate\Support\Facades\Log;

/**
 * RequesterRepliedHandler — Xử lý sự kiện Requester (hoặc Contact) phản hồi.
 */
class RequesterRepliedHandler
{
    protected TimelineService $timelineService;
    protected ?FreshdeskApiService $freshdeskApi;

    public function __construct(TimelineService $timelineService, ?FreshdeskApiService $freshdeskApi = null)
    {
        $this->timelineService = $timelineService;
        $this->freshdeskApi = $freshdeskApi;
    }

    /**
     * Xử lý sự kiện requester_replied.
     */
    public function handle(int $ticketId, array $ticketData, TicketEvent $event): void
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();
        $now = now();

        $eventData = $event->event_data ?? [];
        $convData = $eventData['conversation_data'] ?? [];

        $updatedAtRaw = $convData['updated_at']
            ?? $eventData['ticket_data']['updated_at']
            ?? null;

        $actorId = $convData['actor_id'] ?? null;
        $actorLabel = $actorId ? (string) $actorId : null;

        if ($updatedAtRaw) {
            $now = \Carbon\Carbon::parse($updatedAtRaw);
        }

        if ($actorLabel) {
            $this->timelineService->appendTicketEventLog($ticket, 'ct', 'rep', $now->format('Y-m-d\TH:i:s\Z'), $actorLabel);
        } else {
            $this->timelineService->appendTicketEventLog($ticket, 'ct', 'rep', $now->format('Y-m-d\TH:i:s\Z'));
        }

        Log::info("RequesterRepliedHandler: Logged requester reply to timeline", [
            'ticket_id' => $ticketId,
            'actor_id' => $actorId,
        ]);

        if ($ticket->closed_at && $this->freshdeskApi) {
            if (!$ticket->reopened_at || $ticket->reopened_at->lte($ticket->closed_at)) {
                $minutesDiff = $now->diffInMinutes($ticket->closed_at, true);
                $reopenThreshold = config('freshdesk.reopen_threshold_minutes', 1440);
                $isActionTaken = false;

                $l1Id = null;
                $fdGroup = \App\Models\FreshdeskGroup::active()->where('main_layer', 'L1')->first();
                if ($fdGroup) {
                    $l1Id = $fdGroup->group_id;
                } elseif (!\App\Models\FreshdeskGroup::query()->exists()) {
                    $groupMapping = config('freshdesk.group_mapping', []);
                    foreach ($groupMapping as $id => $name) {
                        if (\Illuminate\Support\Str::contains(strtolower($name), 'l1')) {
                            $l1Id = $id;
                            break;
                        }
                    }
                }

                if ($minutesDiff <= $reopenThreshold) {
                    $fdTicket = $this->freshdeskApi->getTicket($ticketId);
                    $currentTags = $fdTicket['tags'] ?? [];

                    $maxReopen = 0;
                    $filteredTags = [];
                    foreach ($currentTags as $tag) {
                        if (preg_match('/^Reopened(?:[\s_]*\(?(\d+)\)?)?$/i', trim($tag), $matches)) {
                            $num = isset($matches[1]) ? (int) $matches[1] : 1;
                            if ($num > $maxReopen) {
                                $maxReopen = $num;
                            }
                        } else {
                            $filteredTags[] = $tag;
                        }
                    }
                    $newReopenCount = $maxReopen + 1;
                    $filteredTags[] = "Reopened ({$newReopenCount})";

                    $updatePayload = [
                        'status' => 3, // Processing
                        'tags' => $filteredTags,
                    ];

                    if ($l1Id) {
                        $updatePayload['group_id'] = (int) $l1Id;
                    }

                    $this->freshdeskApi->updateTicket($ticketId, $updatePayload);

                    Log::info("RequesterRepliedHandler: Reopened ticket <= threshold", [
                        'ticket_id' => $ticketId,
                        'reopen_count' => $newReopenCount,
                        'threshold_minutes' => $reopenThreshold,
                        'actual_minutes' => $minutesDiff,
                    ]);
                    $isActionTaken = true;

                } else {
                    $requesterId = $eventData['ticket_data']['requester_id']
                        ?? $ticket->requester_id
                        ?? ($convData['actor_type'] === 'contact' ? $convData['actor_id'] : null);

                    $subject = $eventData['ticket_data']['subject'] ?? $ticket->subject;

                    if ($requesterId) {
                        $newTicketPayload = [
                            'requester_id' => (int) $requesterId,
                            'subject' => $subject,
                        ];

                        if ($l1Id) {
                            $newTicketPayload['group_id'] = (int) $l1Id;
                        }

                        $newTicketResult = $this->freshdeskApi->createTicket($newTicketPayload);

                        if ($newTicketResult && isset($newTicketResult['id'])) {
                            $newTicketId = $newTicketResult['id'];
                            $closedDateStr = $ticket->closed_at->format('Y-m-d');
                            $noteBody = "Ticket này được tạo từ phản hồi của khách hàng cho ticket #{$ticketId} (đã đóng vào ngày {$closedDateStr}).";

                            $this->freshdeskApi->addTicketNote($newTicketId, $noteBody, true);

                            $cf = $eventData['ticket_data']['custom_fields'] ?? [];
                            $slaModeKey = null;
                            foreach (array_keys($cf) as $key) {
                                if (str_starts_with($key, 'cf_sla_mode')) {
                                    $slaModeKey = $key;
                                    break;
                                }
                            }

                            if ($slaModeKey && empty($cf[$slaModeKey])) {
                                $this->freshdeskApi->updateTicket($ticketId, [
                                    'custom_fields' => [
                                        $slaModeKey => 'due-driven'
                                    ]
                                ]);
                            }

                            Log::info("RequesterRepliedHandler: Created new linked ticket > threshold", [
                                'old_ticket_id' => $ticketId,
                                'new_ticket_id' => $newTicketId,
                                'threshold_minutes' => $reopenThreshold,
                                'actual_minutes' => $minutesDiff,
                            ]);
                            $isActionTaken = true;
                        }
                    } else {
                        Log::warning("RequesterRepliedHandler: Could not create new linked ticket because requester_id is missing", [
                            'ticket_id' => $ticketId,
                        ]);
                    }
                }

                if ($isActionTaken) {
                    $ticket->reopened_at = $now;
                    $ticket->save();
                }
            } else {
                Log::info("RequesterRepliedHandler: Skipped processing because ticket was already processed for this closure", [
                    'ticket_id' => $ticketId,
                    'closed_at' => $ticket->closed_at->toIso8601String(),
                    'reopened_at' => $ticket->reopened_at->toIso8601String()
                ]);
            }
        }
    }
}
