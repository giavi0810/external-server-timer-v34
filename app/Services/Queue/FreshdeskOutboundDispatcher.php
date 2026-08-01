<?php

namespace App\Services\Queue;

use App\Jobs\ExecuteFreshdeskOutboundOperationJob;
use App\Models\FreshdeskOutboundOperation;
use App\Models\TicketLogicOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreshdeskOutboundDispatcher
{
    public function dispatch(int $limit): DispatchResult
    {
        $ids = FreshdeskOutboundOperation::query()
            ->where(function ($query): void {
                $query->where(fn ($ready) => $ready->where('state', 'ready')->where('available_at', '<=', now()))
                    ->orWhere(fn ($expired) => $expired->whereIn('state', ['dispatched', 'processing'])->where('visibility_at', '<=', now()));
            })
            ->orderBy('available_at')
            ->limit(max(1, $limit))
            ->pluck('operation_id');

        $dispatched = 0;
        $failures = 0;

        foreach ($ids as $id) {
            $claim = DB::transaction(function () use ($id): ?array {
                $operation = FreshdeskOutboundOperation::query()->whereKey($id)->lockForUpdate()->first();
                if (! $operation || ! in_array($operation->state, ['ready', 'dispatched', 'processing'], true)) {
                    return null;
                }

                $expired = $operation->visibility_at && $operation->visibility_at->lte(now());
                if ($operation->state !== 'ready' && ! $expired) {
                    return null;
                }

                $gateBlocked = TicketLogicOutbox::query()
                    ->where('ticket_id', $operation->ticket_id)
                    ->whereIn('state', [
                        'replay_requested',
                        'replay_start_dispatched',
                        'replay_initializing',
                        'replaying',
                        'replay_continue_dispatched',
                    ])
                    ->exists();
                if ($gateBlocked) {
                    return null;
                }

                $token = (string) Str::uuid();
                $operation->forceFill([
                    'state' => 'dispatched',
                    'lease_token' => $token,
                    'visibility_at' => now()->addSeconds(150),
                    'attempt_count' => $operation->attempt_count + 1,
                ])->save();

                return [
                    'operation_id' => $operation->operation_id,
                    'token' => $token,
                    'generation' => $operation->generation,
                    'sync_epoch' => $operation->sync_epoch,
                    'version' => $operation->operation_version,
                ];
            });

            if (! $claim) {
                continue;
            }

            try {
                ExecuteFreshdeskOutboundOperationJob::dispatch(...array_values($claim))
                    ->onQueue('freshdesk-outbound');
                $dispatched++;
            } catch (\Throwable $exception) {
                $failures++;
                FreshdeskOutboundOperation::query()
                    ->whereKey($claim['operation_id'])
                    ->where('lease_token', $claim['token'])
                    ->where('generation', $claim['generation'])
                    ->where('sync_epoch', $claim['sync_epoch'])
                    ->where('operation_version', $claim['version'])
                    ->update([
                        'state' => 'ready',
                        'lease_token' => null,
                        'visibility_at' => null,
                        'available_at' => now()->addSeconds(15),
                        'last_error' => $exception->getMessage(),
                    ]);
                break;
            }
        }

        return new DispatchResult($dispatched, $failures);
    }
}
