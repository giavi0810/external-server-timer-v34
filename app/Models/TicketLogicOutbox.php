<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketLogicOutbox extends Model
{
    protected $fillable = [
        'ticket_id', 'state', 'dispatch_kind', 'requested_generation',
        'acked_generation', 'sync_epoch', 'lease_token', 'replay_run_id',
        'available_at', 'visibility_at', 'heartbeat_at', 'last_error',
    ];

    protected $casts = [
        'requested_generation' => 'integer',
        'acked_generation' => 'integer',
        'sync_epoch' => 'integer',
        'available_at' => 'datetime',
        'visibility_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];
}
