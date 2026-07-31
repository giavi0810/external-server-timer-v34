<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTicketEventJob;
use App\Jobs\StartTicketReplayJob;
use App\Models\TicketLogicOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispatchTicketLogicOutboxCommand extends Command
{
    protected $signature = 'ticket-logic-outbox:dispatch {--limit=250}';
    protected $description = 'Claim PostgreSQL logic outbox rows and dispatch Redis jobs';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ids = TicketLogicOutbox::query()
            ->where(function ($query): void {
                $query->where(function ($ready): void {
                    $ready->whereIn('state', ['ready', 'replay_requested'])
                        ->where('available_at', '<=', now());
                })->orWhere(function ($expired): void {
                    $expired->whereIn('state', ['dispatched', 'processing', 'replay_start_dispatched', 'replay_initializing', 'replaying', 'replay_continue_dispatched'])
                        ->where('visibility_at', '<=', now());
                });
            })
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;
        foreach ($ids as $id) {
            $claim = DB::transaction(function () use ($id): ?array {
                $outbox = TicketLogicOutbox::query()->whereKey($id)->lockForUpdate()->first();
                if (!$outbox) {
                    return null;
                }

                $expired = $outbox->visibility_at && $outbox->visibility_at->lte(now());
                $replay = $outbox->dispatch_kind === 'replay'
                    || str_starts_with($outbox->state, 'replay_')
                    || $outbox->state === 'replaying';
                $continueReplay = in_array($outbox->state, ['replaying', 'replay_continue_dispatched'], true);

                if (!$expired && !in_array($outbox->state, ['ready', 'replay_requested'], true)) {
                    return null;
                }

                $token = (string) Str::uuid();
                $generation = $outbox->requested_generation;
                $state = $replay
                    ? ($continueReplay ? 'replay_continue_dispatched' : 'replay_start_dispatched')
                    : 'dispatched';
                $visibility = now()->addSeconds($replay ? 360 : 150);

                $outbox->forceFill([
                    'state' => $state,
                    'dispatch_kind' => $replay ? 'replay' : 'normal',
                    'lease_token' => $token,
                    'visibility_at' => $visibility,
                    'heartbeat_at' => now(),
                    'last_error' => null,
                ])->save();

                return [
                    'id' => $outbox->id,
                    'ticket_id' => $outbox->ticket_id,
                    'token' => $token,
                    'generation' => $generation,
                    'sync_epoch' => $outbox->sync_epoch,
                    'replay' => $replay,
                    'continue_replay' => $continueReplay,
                ];
            });

            if (!$claim) {
                continue;
            }

            try {
                if ($claim['replay'] && !$claim['continue_replay']) {
                    StartTicketReplayJob::dispatch(
                        $claim['ticket_id'],
                        $claim['token'],
                        $claim['generation'],
                        $claim['sync_epoch']
                    )->onQueue('ticket-logic');
                } else {
                    ProcessTicketEventJob::dispatch(
                        $claim['ticket_id'],
                        $claim['continue_replay'],
                        $claim['continue_replay'],
                        $claim['token'],
                        $claim['generation'],
                        $claim['sync_epoch']
                    )->onQueue('ticket-logic');
                }
                $dispatched++;
            } catch (\Throwable $exception) {
                TicketLogicOutbox::query()
                    ->whereKey($claim['id'])
                    ->where('lease_token', $claim['token'])
                    ->where('requested_generation', $claim['generation'])
                    ->update([
                        'state' => $claim['replay']
                            ? ($claim['continue_replay'] ? 'replaying' : 'replay_requested')
                            : 'ready',
                        'lease_token' => null,
                        'visibility_at' => $claim['continue_replay'] ? now()->addSeconds(5) : null,
                        'available_at' => now()->addSeconds(5),
                        'last_error' => $exception->getMessage(),
                    ]);
                Log::warning('Logic outbox Redis dispatch failed', $claim + [
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Dispatched {$dispatched} ticket logic job(s).");
        return self::SUCCESS;
    }
}
