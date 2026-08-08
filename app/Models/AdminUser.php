<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SLA_MANAGER = 'sla_manager';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_SLA_MANAGER,
        self::ROLE_VIEWER,
    ];

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class);
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
