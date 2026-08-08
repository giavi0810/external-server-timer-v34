<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id',
        'username',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Admin audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Admin audit logs are append-only.'));
    }
}
