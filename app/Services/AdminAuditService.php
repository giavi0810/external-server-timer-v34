<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;

class AdminAuditService
{
    public function record(
        Request $request,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?AdminUser $actor = null,
    ): AdminAuditLog {
        $actor ??= $request->attributes->get('admin_user');

        return AdminAuditLog::create([
            'admin_user_id' => $actor?->id,
            'username' => $actor?->username ?? (string) $request->session()->get('admin_username', 'unknown'),
            'actor_role' => $actor?->role ?? $request->session()->get('admin_role'),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        unset($values['password'], $values['password_confirmation'], $values['remember_token']);

        return $values;
    }
}
