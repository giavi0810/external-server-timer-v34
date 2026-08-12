<?php

namespace App\Http\Controllers;

use App\Models\FreshdeskGroup;
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
     * Accept a Change Group command from the Freshdesk frontend app.
     * POST /api/tickets/change-group
     */
    public function changeGroup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer|min:1',
            'old_group_id' => 'required|integer|min:1',
            'old_group_name' => 'required|string|max:255',
            'new_group_id' => 'required|integer|min:1|different:old_group_id',
            'new_group_name' => 'required|string|max:255',
            'agent_name' => 'required|string|max:255',
            'changed_at' => 'required|date',
            'request_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $ticketId = (int) $validated['ticket_id'];
        $newGroupId = (string) $validated['new_group_id'];

        try {
            if (! Ticket::query()->where('ticket_id', $ticketId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy ticket trong hệ thống.',
                ], 404);
            }

            if (! FreshdeskGroup::query()->active()->whereKey($newGroupId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group đích không tồn tại hoặc đã ngừng hoạt động.',
                ], 422);
            }

            $clientKey = trim((string) $request->header(
                'Idempotency-Key',
                $validated['request_id']
            ));
            $idempotencyKey = 'change-group:'.hash('sha256', json_encode([
                'ticket_id' => $ticketId,
                'client_key' => $clientKey,
            ], JSON_THROW_ON_ERROR));
            $payload = [
                'old_group_id' => (int) $validated['old_group_id'],
                'old_group_name' => trim($validated['old_group_name']),
                'new_group_id' => (int) $validated['new_group_id'],
                'new_group_name' => trim($validated['new_group_name']),
                'agent_name' => trim($validated['agent_name']),
                'changed_at' => Carbon::parse($validated['changed_at'])->toIso8601String(),
                'request_id' => $validated['request_id'],
            ];

            $operation = $this->outbound->enqueueCommand(
                $ticketId,
                'change_group',
                $idempotencyKey,
                $payload
            );

            Log::info('TicketActionController: Change Group accepted', [
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
            Log::error('TicketActionController: Error accepting Change Group', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể tiếp nhận yêu cầu thay đổi Group.',
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
