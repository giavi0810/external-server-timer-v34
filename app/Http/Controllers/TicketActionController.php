<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use App\Services\Sla\HistoryService;
use App\Services\Sla\TimelineService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TicketActionController extends Controller
{
    protected FreshdeskApiService $freshdeskApi;
    protected TimelineService $timelineService;
    protected HistoryService $historyService;

    public function __construct(
        FreshdeskApiService $freshdeskApi,
        TimelineService $timelineService,
        HistoryService $historyService
    ) {
        $this->freshdeskApi = $freshdeskApi;
        $this->timelineService = $timelineService;
        $this->historyService = $historyService;
    }

    /**
     * Handle Change Due Date request from Frontend App Timer.
     * POST /api/tickets/change-due-date
     */
    public function changeDueDate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer',
            'new_due_date' => 'required|date',
            'processing_phase' => 'nullable|string',
            'reason' => 'nullable|string',
            'agent_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $ticketId = (int) $request->input('ticket_id');

        // Atomic lock (10s) to prevent double submission
        $lock = Cache::lock("change_due_date_lock_{$ticketId}", 10);

        if (!$lock->get()) {
            Log::warning("TicketActionController: Request rejected due to lock", ['ticket_id' => $ticketId]);
            return response()->json([
                'success' => false,
                'message' => 'Hệ thống đang xử lý yêu cầu cho ticket này, vui lòng đợi.',
            ], 429);
        }

        try {
            $rawDueDate = $request->input('new_due_date');
            $isoDueDate = Carbon::parse($rawDueDate)->toIso8601String();
            $processingPhase = $request->input('processing_phase');
            $reason = $request->input('reason');
            $agentName = $request->input('agent_name');

            Log::info("TicketActionController: Change Due Date request received", [
                'ticket_id' => $ticketId,
                'new_due' => $isoDueDate,
                'phase' => $processingPhase,
                'reason' => $reason,
                'agent_name' => $agentName,
            ]);

            $fdTicket = $this->freshdeskApi->getTicket($ticketId);
            if (!$fdTicket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể lấy thông tin ticket từ Freshdesk',
                ], 404);
            }

            $cf = $fdTicket['custom_fields'] ?? [];

            $countKey = 'cf_number_of_due_date_changes';
            $processingModeKey = 'cf_processing_mode';
            $phaseKey = 'cf_processing_phase';
            $reasonKey = 'cf_change_due_reason';

            foreach (array_keys($cf) as $key) {
                if (str_starts_with($key, 'cf_number_of_due_date_changes')) {
                    $countKey = $key;
                }
                if (str_starts_with($key, 'cf_processing_mode')
                    || str_starts_with($key, 'cf_sla_mode')
                ) {
                    $processingModeKey = $key;
                }
                if (str_starts_with($key, 'cf_processing_phase')) {
                    $phaseKey = $key;
                }
                if (str_starts_with($key, 'cf_change_due_reason')) {
                    $reasonKey = $key;
                }
            }

            $currentCount = (int) ($cf[$countKey] ?? 0);
            $nextCount = $currentCount + 1;

            $updatePayload = [
                'due_by' => $isoDueDate,
                'custom_fields' => [
                    $countKey => $nextCount,
                    $processingModeKey => 'due-driven',
                ],
            ];

            if ($processingPhase) {
                $updatePayload['custom_fields'][$phaseKey] = $processingPhase;
            }
            if ($reason) {
                $updatePayload['custom_fields'][$reasonKey] = $reason;
            }

            $updated = $this->freshdeskApi->updateTicket($ticketId, $updatePayload);

            if (!$updated) {
                $errorCtx = $this->freshdeskApi->getLastErrorContext();
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể cập nhật ticket trên Freshdesk',
                    'error_context' => $errorCtx,
                ], 502);
            }

            // Update local DB
            $localTicket = Ticket::where('ticket_id', $ticketId)->first();
            if ($localTicket) {
                $localTicket->getOrCreateTtrMetric()->update([
                    'processing_mode' => 'due-driven',
                    'latest_due_date_ttr' => Carbon::parse($isoDueDate),
                ]);
            }

            $tagResult = $this->freshdeskApi->addIncrementedTagToTicket($ticketId, 'due_date_change');

            $noteLines = [
                "Thay đổi Due Date lần {$nextCount}",
                "- Due Date mới: {$isoDueDate}",
                "- Processing Mode: due-driven",
            ];
            if ($processingPhase) {
                $noteLines[] = "- Processing Phase: {$processingPhase}";
            }
            if ($reason) {
                $noteLines[] = "- Lý do: {$reason}";
            }
            if ($tagResult) {
                $noteLines[] = "- Tag: {$tagResult}";
            }

            $this->freshdeskApi->addTicketNote($ticketId, implode("\n", $noteLines), true);

            Log::info("TicketActionController: Change Due Date completed", [
                'ticket_id' => $ticketId,
                'count' => $nextCount,
                'tag' => $tagResult,
            ]);

            return response()->json([
                'success' => true,
                'ticket_id' => $ticketId,
                'change_count' => $nextCount,
                'tag' => $tagResult,
            ]);
        } catch (Throwable $e) {
            Log::error("TicketActionController: Error handling Change Due Date", [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra trong quá trình xử lý.',
            ], 500);
        } finally {
            $lock->release();
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
