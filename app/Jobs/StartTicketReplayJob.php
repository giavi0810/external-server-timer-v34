<?php

namespace App\Jobs;

use App\Models\TicketLogicOutbox;
use App\Services\Sla\TicketReplayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StartTicketReplayJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $leaseToken,
        public readonly int $generation,
        public readonly int $syncEpoch
    ) {
    }

    public function handle(TicketReplayService $replayService): void
    {
        $claimed = TicketLogicOutbox::query()
            ->where('ticket_id', $this->ticketId)
            ->where('state', 'replay_start_dispatched')
            ->where('lease_token', $this->leaseToken)
            ->where('requested_generation', $this->generation)
            ->where('sync_epoch', $this->syncEpoch)
            ->update([
                'state' => 'replay_initializing',
                'visibility_at' => now()->addSeconds(330),
                'heartbeat_at' => now(),
            ]);

        if ($claimed !== 1) {
            Log::notice('Stale StartTicketReplayJob ignored', ['ticket_id' => $this->ticketId]);
            return;
        }

        $lock = Cache::lock("ticket_processing:{$this->ticketId}", 330);
        if (!$lock->get()) {
            TicketLogicOutbox::query()
                ->where('ticket_id', $this->ticketId)
                ->where('lease_token', $this->leaseToken)
                ->where('state', 'replay_initializing')
                ->update([
                    'state' => 'replay_requested',
                    'available_at' => now()->addSeconds(5),
                    'lease_token' => null,
                    'visibility_at' => null,
                ]);
            return;
        }

        try {
            $replayService->prepare($this->ticketId);
            $runId = (string) Str::uuid();
            $continued = TicketLogicOutbox::query()
                ->where('ticket_id', $this->ticketId)
                ->where('state', 'replay_initializing')
                ->where('lease_token', $this->leaseToken)
                ->where('requested_generation', $this->generation)
                ->where('sync_epoch', $this->syncEpoch)
                ->update([
                    'state' => 'replaying',
                    'replay_run_id' => $runId,
                    'visibility_at' => now()->addSeconds(150),
                    'heartbeat_at' => now(),
                ]);

            if ($continued !== 1) {
                return;
            }

            ProcessTicketEventJob::dispatch(
                $this->ticketId,
                true,
                true,
                $this->leaseToken,
                $this->generation,
                $this->syncEpoch
            )->onQueue('ticket-logic');
        } finally {
            $lock->release();
        }
    }
}
