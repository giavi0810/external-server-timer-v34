<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends Model
{
    protected $fillable = [
        'ticket_type',
        'priority',
        'version',
        'total_seconds',
        'l1_seconds',
        'l2_seconds',
        'l3_seconds',
        'l4_seconds',
        'rt_seconds',
    ];

    protected $casts = [
        'version' => 'integer',
        'total_seconds' => 'integer',
        'l1_seconds' => 'integer',
        'l2_seconds' => 'integer',
        'l3_seconds' => 'integer',
        'l4_seconds' => 'integer',
        'rt_seconds' => 'integer',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(TicketSlaStage::class);
    }

    public function scopeLatestVersion($query)
    {
        return $query->orderByDesc('version');
    }

    public static function getPolicy(string $ticketType, string $priority): ?self
    {
        // 1. Tìm chính xác theo ticket_type và priority
        $policy = self::where('ticket_type', $ticketType)
            ->where('priority', $priority)
            ->latestVersion()
            ->first();

        if ($policy) {
            return $policy;
        }

        // 2. Tự động chuẩn hóa (VD: "VVIP SLA" -> "VVIP")
        $normalizedType = trim(preg_replace('/\s+SLA$/i', '', $ticketType));
        if ($normalizedType !== $ticketType) {
            $policy = self::where('ticket_type', $normalizedType)
                ->where('priority', $priority)
                ->latestVersion()
                ->first();

            if ($policy) {
                return $policy;
            }
        }

        // 3. Fallback: lấy policy theo priority nếu ticket_type không có cấu hình riêng
        return self::where('priority', $priority)
            ->latestVersion()
            ->first();
    }

    public function getTimeAllocation(): array
    {
        return [
            'total' => $this->total_seconds,
            'L1' => $this->l1_seconds,
            'L2' => $this->l2_seconds,
            'L3' => $this->l3_seconds,
            'L4' => $this->l4_seconds,
            'RT' => $this->rt_seconds,
        ];
    }
}
