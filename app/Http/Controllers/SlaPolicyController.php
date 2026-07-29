<?php

namespace App\Http\Controllers;

use App\Models\SlaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SlaPolicyController extends Controller
{
    /**
     * GET /admin/sla-policies
     * Danh sách policy, group by ticket_type + priority, chỉ hiện version mới nhất.
     */
    public function index(Request $request): JsonResponse
    {
        $ticketType = $request->input('ticket_type');
        $priority = $request->input('priority');

        // Sub-query: lấy version cao nhất cho mỗi combo (ticket_type, priority)
        $latestVersions = DB::table('sla_policies')
            ->select('ticket_type', 'priority', DB::raw('MAX(version) as max_version'));

        if ($ticketType) {
            $latestVersions->where('ticket_type', $ticketType);
        }
        if ($priority) {
            $latestVersions->where('priority', $priority);
        }

        $latestVersions->groupBy('ticket_type', 'priority');

        $policies = SlaPolicy::query()
            ->joinSub($latestVersions, 'latest', function ($join) {
                $join->on('sla_policies.ticket_type', '=', 'latest.ticket_type')
                    ->on('sla_policies.priority', '=', 'latest.priority')
                    ->on('sla_policies.version', '=', 'latest.max_version');
            })
            ->select('sla_policies.*')
            ->orderBy('sla_policies.ticket_type')
            ->orderByRaw("CASE sla_policies.priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 WHEN 'Low' THEN 4 END")
            ->get();

        return response()->json([
            'data' => $policies->map(fn($p) => $this->formatPolicy($p)),
            'total' => $policies->count(),
        ]);
    }

    /**
     * GET /admin/sla-policies/{id}
     */
    public function show(int $id): JsonResponse
    {
        $policy = SlaPolicy::find($id);
        if (!$policy) {
            return response()->json(['error' => 'Policy not found'], 404);
        }

        return response()->json(['data' => $this->formatPolicy($policy)]);
    }

    /**
     * POST /admin/sla-policies
     * Tạo policy mới (ticket_type + priority chưa tồn tại).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validatePolicy($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ticketType = $request->input('ticket_type');
        $priority = $request->input('priority');

        // Kiểm tra đã tồn tại chưa
        $existing = SlaPolicy::where('ticket_type', $ticketType)
            ->where('priority', $priority)
            ->exists();

        if ($existing) {
            return response()->json([
                'error' => "Policy for type '{$ticketType}' + priority '{$priority}' already exists. Use PUT to update.",
            ], 409);
        }

        $hours = $this->extractHours($request);

        $budgetError = $this->validateBudgetSum($hours);
        if ($budgetError) {
            return $budgetError;
        }

        $policy = SlaPolicy::create([
            'ticket_type' => $ticketType,
            'priority' => $priority,
            'version' => 1,
            'total_seconds' => (int) ($hours['total'] * 3600),
            'l1_seconds' => (int) ($hours['L1'] * 3600),
            'l2_seconds' => (int) ($hours['L2'] * 3600),
            'l3_seconds' => (int) ($hours['L3'] * 3600),
            'l4_seconds' => (int) ($hours['L4'] * 3600),
            'rt_seconds' => (int) ($hours['RT'] * 3600),
        ]);

        return response()->json([
            'message' => 'Policy created successfully',
            'data' => $this->formatPolicy($policy),
        ], 201);
    }

    /**
     * PUT /admin/sla-policies/{id}
     * Cập nhật policy → tạo version mới (giữ nguyên bản ghi cũ cho audit).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $current = SlaPolicy::find($id);
        if (!$current) {
            return response()->json(['error' => 'Policy not found'], 404);
        }

        $validator = $this->validatePolicyUpdate($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $hours = $this->extractHoursForUpdate($request, $current);

        $budgetError = $this->validateBudgetSum($hours);
        if ($budgetError) {
            return $budgetError;
        }

        $maxVersion = SlaPolicy::where('ticket_type', $current->ticket_type)
            ->where('priority', $current->priority)
            ->max('version');

        $newPolicy = SlaPolicy::create([
            'ticket_type' => $current->ticket_type,
            'priority' => $current->priority,
            'version' => $maxVersion + 1,
            'total_seconds' => (int) ($hours['total'] * 3600),
            'l1_seconds' => (int) ($hours['L1'] * 3600),
            'l2_seconds' => (int) ($hours['L2'] * 3600),
            'l3_seconds' => (int) ($hours['L3'] * 3600),
            'l4_seconds' => (int) ($hours['L4'] * 3600),
            'rt_seconds' => (int) ($hours['RT'] * 3600),
        ]);

        return response()->json([
            'message' => 'Policy updated (new version created)',
            'previous_version' => $current->version,
            'data' => $this->formatPolicy($newPolicy),
        ]);
    }

    /**
     * DELETE /admin/sla-policies/{id}
     * Xóa policy (chỉ khi chưa có ticket_sla_stage nào reference).
     */
    public function destroy(int $id): JsonResponse
    {
        $policy = SlaPolicy::find($id);
        if (!$policy) {
            return response()->json(['error' => 'Policy not found'], 404);
        }

        $stageCount = DB::table('ticket_sla_stages')
            ->where('sla_policy_id', $policy->id)
            ->count();

        if ($stageCount > 0) {
            return response()->json([
                'error' => "Cannot delete: {$stageCount} ticket SLA stage(s) reference this policy.",
            ], 409);
        }

        $policy->delete();

        return response()->json(['message' => 'Policy deleted successfully']);
    }

    /**
     * GET /admin/sla-policies/{id}/history
     * Xem lịch sử version của một policy (theo ticket_type + priority).
     */
    public function history(int $id): JsonResponse
    {
        $policy = SlaPolicy::find($id);
        if (!$policy) {
            return response()->json(['error' => 'Policy not found'], 404);
        }

        $versions = SlaPolicy::where('ticket_type', $policy->ticket_type)
            ->where('priority', $policy->priority)
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'ticket_type' => $policy->ticket_type,
            'priority' => $policy->priority,
            'versions' => $versions->map(fn($p) => $this->formatPolicy($p)),
        ]);
    }

    // ─── Private helpers ────────────────────────────────────────

    private function formatPolicy(SlaPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'ticket_type' => $policy->ticket_type,
            'priority' => $policy->priority,
            'version' => $policy->version,
            'total_hours' => round($policy->total_seconds / 3600, 2),
            'L1_hours' => round($policy->l1_seconds / 3600, 2),
            'L2_hours' => round($policy->l2_seconds / 3600, 2),
            'L3_hours' => round($policy->l3_seconds / 3600, 2),
            'L4_hours' => round($policy->l4_seconds / 3600, 2),
            'RT_hours' => round($policy->rt_seconds / 3600, 2),
            'total_seconds' => $policy->total_seconds,
            'l1_seconds' => $policy->l1_seconds,
            'l2_seconds' => $policy->l2_seconds,
            'l3_seconds' => $policy->l3_seconds,
            'l4_seconds' => $policy->l4_seconds,
            'rt_seconds' => $policy->rt_seconds,
            'created_at' => $policy->created_at?->toIso8601String(),
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }

    private function validatePolicy(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'ticket_type' => 'required|string|max:100',
            'priority' => 'required|string|in:Urgent,High,Medium,Low',
            'total' => 'required|numeric|min:0',
            'L1' => 'required|numeric|min:0',
            'L2' => 'required|numeric|min:0',
            'L3' => 'required|numeric|min:0',
            'L4' => 'required|numeric|min:0',
            'RT' => 'required|numeric|min:0',
        ]);
    }

    private function validatePolicyUpdate(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'total' => 'sometimes|numeric|min:0',
            'L1' => 'sometimes|numeric|min:0',
            'L2' => 'sometimes|numeric|min:0',
            'L3' => 'sometimes|numeric|min:0',
            'L4' => 'sometimes|numeric|min:0',
            'RT' => 'sometimes|numeric|min:0',
        ]);
    }

    private function extractHours(Request $request): array
    {
        return [
            'total' => (float) $request->input('total'),
            'L1' => (float) $request->input('L1'),
            'L2' => (float) $request->input('L2'),
            'L3' => (float) $request->input('L3'),
            'L4' => (float) $request->input('L4'),
            'RT' => (float) $request->input('RT'),
        ];
    }

    private function extractHoursForUpdate(Request $request, SlaPolicy $current): array
    {
        return [
            'total' => $request->has('total') ? (float) $request->input('total') : $current->total_seconds / 3600,
            'L1' => $request->has('L1') ? (float) $request->input('L1') : $current->l1_seconds / 3600,
            'L2' => $request->has('L2') ? (float) $request->input('L2') : $current->l2_seconds / 3600,
            'L3' => $request->has('L3') ? (float) $request->input('L3') : $current->l3_seconds / 3600,
            'L4' => $request->has('L4') ? (float) $request->input('L4') : $current->l4_seconds / 3600,
            'RT' => $request->has('RT') ? (float) $request->input('RT') : $current->rt_seconds / 3600,
        ];
    }

    private function validateBudgetSum(array $hours): ?JsonResponse
    {
        $sum = $hours['L1'] + $hours['L2'] + $hours['L3'] + $hours['L4'];
        $total = $hours['total'];

        // So sánh với tolerance nhỏ để tránh lỗi floating point
        if (abs($total - $sum) > 0.001) {
            return response()->json([
                'error' => "total ({$total}h) must equal L1 + L2 + L3 + L4 ({$sum}h)",
            ], 422);
        }

        return null;
    }
}
