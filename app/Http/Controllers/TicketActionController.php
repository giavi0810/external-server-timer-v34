<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\Queue\FreshdeskOutboundService;
use App\Services\Sla\HistoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TicketActionController extends Controller
{
    public function __construct(
        protected FreshdeskOutboundService $outbound,
        protected HistoryService $historyService
    ) {}

    /**
     * Handle Change Due Date request from Frontend App Timer.
     * POST /api/tickets/change-due-date
     */
    public function changeDueDate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer|min:1',
            'new_due_date' => 'required|date',
            'processing_phase' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'agent_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $ticketId = (int) $request->input('ticket_id');

        try {
            if (! Ticket::query()->where('ticket_id', $ticketId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy ticket trong hệ thống.',
                ], 404);
            }

            $payload = [
                'new_due_date' => Carbon::parse($request->input('new_due_date'))
                    ->utc()
                    ->toIso8601String(),
                'processing_phase' => $request->input('processing_phase'),
                'reason' => $request->input('reason'),
                'agent_name' => $request->input('agent_name'),
            ];
            $clientKey = trim((string) $request->header('Idempotency-Key', ''));
            $businessIdentity = $clientKey !== ''
                ? ['client_key' => $clientKey]
                : $payload;
            $idempotencyKey = 'change-due-date:'.hash('sha256', json_encode([
                'ticket_id' => $ticketId,
                'request' => $businessIdentity,
            ], JSON_THROW_ON_ERROR));

            $operation = $this->outbound->enqueueCommand(
                $ticketId,
                'change_due_date',
                $idempotencyKey,
                $payload
            );

            Log::info('TicketActionController: Change Due Date accepted', [
                'ticket_id' => $ticketId,
                'operation_id' => $operation->operation_id,
                'operation_state' => $operation->state,
            ]);

            return response()->json([
                'success' => true,
                'accepted' => true,
                'ticket_id' => $ticketId,
                'operation_id' => $operation->operation_id,
                'status' => $operation->state,
                'duplicate' => ! $operation->wasRecentlyCreated,
            ], 202);
        } catch (Throwable $e) {
            Log::error('TicketActionController: Error accepting Change Due Date', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể tiếp nhận yêu cầu thay đổi Due Date.',
            ], 500);
        }
    }

    /**
     * Get timeline history tables for Popup Modal.
     * GET /api/tickets/{id}/history
     */
    public function getHistory(int $ticketId): JsonResponse
    {
        $tables = $this->historyService->buildTables($ticketId);

        return response()->json([
            'success' => true,
            'data' => $tables,
        ]);
    }
}
