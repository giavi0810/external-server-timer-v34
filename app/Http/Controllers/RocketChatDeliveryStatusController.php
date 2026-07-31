<?php

namespace App\Http\Controllers;

use App\Models\RocketChatDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RocketChatDeliveryStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(RocketChatDeliveryStatus::statuses())],
            'event_code' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'cursor' => ['nullable', 'string'],
        ]);

        $timezone = (string) config(
            'services.rocketchat.alert_timezone',
            'Asia/Ho_Chi_Minh'
        );
        $date = $validated['date'] ?? now($timezone)->format('Y-m-d');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->utc();
        $to = $from->setTimezone($timezone)->addDay()->utc();

        $baseQuery = RocketChatDeliveryStatus::query()
            ->where('attempted_at', '>=', $from)
            ->where('attempted_at', '<', $to);

        if (isset($validated['event_code'])) {
            $baseQuery->where('event_code', $validated['event_code']);
        }

        $summaryRows = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $query = clone $baseQuery;
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $limit = (int) ($validated['limit'] ?? 50);
        $page = $query
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'date' => $date,
            'timezone' => $timezone,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'event_code' => $validated['event_code'] ?? null,
            ],
            'summary' => [
                'total' => (int) $summaryRows->sum(),
                'pending' => (int) ($summaryRows[RocketChatDeliveryStatus::STATUS_PENDING] ?? 0),
                'success' => (int) ($summaryRows[RocketChatDeliveryStatus::STATUS_SUCCESS] ?? 0),
                'failed' => (int) ($summaryRows[RocketChatDeliveryStatus::STATUS_FAILED] ?? 0),
                'unknown' => (int) ($summaryRows[RocketChatDeliveryStatus::STATUS_UNKNOWN] ?? 0),
            ],
            'data' => collect($page->items())->map(static fn (RocketChatDeliveryStatus $item) => [
                'delivery_id' => $item->delivery_id,
                'event_code' => $item->event_code,
                'status' => $item->status,
                'http_status' => $item->http_status,
                'rocketchat_message_id' => $item->rocketchat_message_id,
                'attempt_count' => $item->attempt_count,
                'attempted_at' => $item->attempted_at?->utc()->toIso8601String(),
                'completed_at' => $item->completed_at?->utc()->toIso8601String(),
            ])->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }
}
