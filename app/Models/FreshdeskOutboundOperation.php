<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreshdeskOutboundOperation extends Model
{
    protected $primaryKey = 'operation_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'operation_id', 'idempotency_key', 'ticket_id', 'operation_type',
        'coalesce_key', 'generation', 'sync_epoch', 'operation_version',
        'payload', 'state', 'lease_token', 'available_at', 'visibility_at',
        'attempt_count', 'last_error', 'remote_id', 'completed_at',
        'reconcile_only',
    ];

    protected $casts = [
        'payload' => 'array',
        'generation' => 'integer',
        'sync_epoch' => 'integer',
        'operation_version' => 'integer',
        'attempt_count' => 'integer',
        'available_at' => 'datetime',
        'visibility_at' => 'datetime',
        'completed_at' => 'datetime',
        'reconcile_only' => 'boolean',
    ];
}
