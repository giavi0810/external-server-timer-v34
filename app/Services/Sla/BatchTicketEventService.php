<?php

namespace App\Services\Sla;

use App\Jobs\ProcessTicketEventJob;
use App\Models\FreshdeskGroup;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Webhooks\FreshdeskEventNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BatchTicketEventService
{
    public function __construct(
        private readonly FreshdeskEventNormalizer $eventNormalizer
    ) {
    }

    public function ingest(array $events): array
    {
        $indexedEvents = collect($events)
            ->map(fn (array $event, int $index) => ['index' => $index, 'event' => $event])
            ->sort(function (array $a, array $b): int {
                $timeOrder = Carbon::parse($a['event']['event_timestamp'])->format('U.u')
                    <=> Carbon::parse($b['event']['event_timestamp'])->format('U.u');

                return $timeOrder !== 0 ? $timeOrder : $a['index'] <=> $b['index'];
            })
            ->groupBy(fn (array $item) => (int) $item['event']['ticket_id']);

        $results = [];
        $dispatches = [];

        foreach ($indexedEvents as $ticketId => $items) {
            $normalizedTicketId = (int) $ticketId;
            $groupResult = DB::transaction(function () use ($normalizedTicketId, $items): array {
                return $this->ingestTicketEvents($normalizedTicketId, $items->values()->all());
            });

            array_push($results, ...$groupResult['results']);
            if ($groupResult['accepted_count'] > 0) {
                $dispatches[(int) $ticketId] = $groupResult['replay_required'];
            }
        }

        foreach ($dispatches as $ticketId => $replayRequired) {
            ProcessTicketEventJob::dispatch($ticketId, $replayRequired, $replayRequired)
                ->delay(now()->addSecond());
        }

        usort($results, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return [
            'accepted_count' => count(array_filter($results, fn (array $result) => $result['status'] === 'accepted')),
            'duplicate_count' => count(array_filter($results, fn (array $result) => $result['status'] === 'duplicate')),
            'rejected_count' => count(array_filter($results, fn (array $result) => $result['status'] === 'rejected')),
            'affected_tickets' => array_values(array_map('intval', array_keys($dispatches))),
            'replay_tickets' => array_values(array_map(
                'intval',
                array_keys(array_filter($dispatches))
            )),
            'results' => array_map(function (array $result): array {
                unset($result['index']);
                return $result;
            }, $results),
        ];
    }

    private function ingestTicketEvents(int $ticketId, array $items): array
    {
        $normalizedItems = array_map(function (array $item): array {
            $item['event'] = $this->eventNormalizer->normalize($item['event']);
            return $item;
        }, $items);

        $ticket = Ticket::query()->where('ticket_id', $ticketId)->lockForUpdate()->first();
        $incomingCreation = collect($normalizedItems)
            ->first(fn (array $item) => $item['event']['event_type'] === TicketEvent::EVENT_TICKET_CREATED);

        if (!$ticket && !$incomingCreation) {
            return [
                'accepted_count' => 0,
                'replay_required' => false,
                'results' => array_map(
                    fn (array $item) => $this->result($item, 'rejected', null, 'ticket_not_found_without_creation_event'),
                    $normalizedItems
                ),
            ];
        }

        if (!$ticket) {
            $ticket = $this->createTicketFromEvent($ticketId, $incomingCreation['event']);
        }

        $latestProcessedAt = TicketEvent::query()
            ->where('ticket_id', $ticketId)
            ->where('status', TicketEvent::STATUS_PROCESSED)
            ->max('event_timestamp');
        $hasBaseline = TicketEvent::query()
            ->where('ticket_id', $ticketId)
            ->where('event_type', TicketEvent::EVENT_TICKET_CREATED)
            ->exists() || $incomingCreation !== null;

        $acceptedCount = 0;
        $replayRequired = false;
        $results = [];

        foreach ($normalizedItems as $item) {
            $event = $item['event'];
            $eventAt = Carbon::parse($event['event_timestamp']);
            $isLate = $latestProcessedAt !== null && $eventAt->lt(Carbon::parse($latestProcessedAt));

            if ($isLate && !$hasBaseline) {
                $results[] = $this->result($item, 'rejected', null, 'late_event_without_ticket_created_baseline');
                continue;
            }

            $actor = $event['conversation_data']['actor_id'] ?? 'none';
            $idempotencyKey = TicketEvent::makeIdempotencyKey(
                $ticketId,
                $event['event_type'],
                $eventAt,
                $actor
            );
            $eventData = ['ticket_data' => $event['ticket_data'] ?? []];
            if (!empty($event['conversation_data'])) {
                $eventData['conversation_data'] = $event['conversation_data'];
            }

            $inserted = DB::table('ticket_events')->insertOrIgnore([
                'ticket_id' => $ticketId,
                'idempotency_key' => $idempotencyKey,
                'event_type' => $event['event_type'],
                'event_data' => json_encode($eventData, JSON_THROW_ON_ERROR),
                'field_changes' => json_encode($event['changes'] ?? [], JSON_THROW_ON_ERROR),
                'status' => TicketEvent::STATUS_PENDING,
                'attempt_count' => 0,
                'event_timestamp' => $eventAt,
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                $results[] = $this->result($item, 'duplicate', $idempotencyKey);
                continue;
            }

            $acceptedCount++;
            $replayRequired = $replayRequired || $isLate;
            $results[] = $this->result($item, 'accepted', $idempotencyKey);
        }

        return [
            'accepted_count' => $acceptedCount,
            'replay_required' => $replayRequired,
            'results' => $results,
        ];
    }

    private function createTicketFromEvent(int $ticketId, array $event): Ticket
    {
        $data = $event['ticket_data'] ?? [];
        $groupId = $data['group_id'] ?? null;
        if ($groupId !== null) {
            FreshdeskGroup::firstOrCreate(
                ['group_id' => (string) $groupId],
                ['name' => "Freshdesk Group {$groupId}", 'main_layer' => 'L1', 'is_active' => true]
            );
        }

        return Ticket::create([
            'ticket_id' => $ticketId,
            'subject' => $data['subject'] ?? null,
            'status' => $data['status'] ?? 'Open',
            'priority' => $data['priority'] ?? 'Medium',
            'ticket_type' => $data['ticket_type'] ?? 'VIP',
            'group_id' => $groupId,
            'requester_id' => $data['requester_id'] ?? null,
            'fd_created_at' => $data['created_at'] ?? $event['event_timestamp'],
        ]);
    }

    private function result(array $item, string $status, ?string $idempotencyKey, ?string $reason = null): array
    {
        return array_filter([
            'index' => $item['index'],
            'recovery_id' => $item['event']['recovery_id'] ?? null,
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
            'reason' => $reason,
        ], fn (mixed $value) => $value !== null);
    }
}
