<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\SlaPolicy;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminSlaPolicyWebController extends Controller
{
    private const PRIORITIES = ['Urgent', 'High', 'Medium', 'Low'];

    public function __construct(private readonly AdminAuditService $auditService)
    {
    }

    public function index(): View
    {
        $latestVersions = DB::table('sla_policies')
            ->select('ticket_type', 'priority', DB::raw('MAX(version) AS max_version'))
            ->groupBy('ticket_type', 'priority');

        $policies = SlaPolicy::query()
            ->joinSub($latestVersions, 'latest', function ($join): void {
                $join->on('sla_policies.ticket_type', '=', 'latest.ticket_type')
                    ->on('sla_policies.priority', '=', 'latest.priority')
                    ->on('sla_policies.version', '=', 'latest.max_version');
            })
            ->select('sla_policies.*')
            ->orderBy('sla_policies.ticket_type')
            ->orderByRaw("CASE sla_policies.priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 WHEN 'Low' THEN 4 END")
            ->get();

        return view('admin.sla_policies.index', [
            'policies' => $policies,
            'auditLogs' => AdminAuditLog::query()
                ->where('entity_type', 'sla_policy')
                ->latest('created_at')
                ->limit(50)
                ->get(),
            'canManage' => in_array(session('admin_role'), ['super_admin', 'sla_manager'], true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBatch($request);
        $ticketType = trim($validated['ticket_type']);
        $confirmed = $request->boolean('confirm_versions');
        $secondsByPriority = [];

        foreach (self::PRIORITIES as $priority) {
            $secondsByPriority[$priority] = $this->toSeconds($validated['policies'][$priority]);
            $this->ensureValidBudget($secondsByPriority[$priority], "policies.{$priority}.total");
        }

        $duplicates = SlaPolicy::query()
            ->where('ticket_type', $ticketType)
            ->whereIn('priority', self::PRIORITIES)
            ->select('priority', DB::raw('MAX(version) AS current_version'))
            ->groupBy('priority')
            ->orderByRaw("CASE priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 WHEN 'Low' THEN 4 END")
            ->get()
            ->map(fn (SlaPolicy $policy): array => [
                'priority' => $policy->priority,
                'current_version' => (int) $policy->current_version,
                'next_version' => (int) $policy->current_version + 1,
            ])
            ->values()
            ->all();

        if ($duplicates !== [] && ! $confirmed) {
            return redirect()
                ->route('admin.sla-policies.index')
                ->withInput()
                ->with('sla_batch_warning', [
                    'ticket_type' => $ticketType,
                    'duplicates' => $duplicates,
                ]);
        }

        $created = DB::transaction(function () use ($request, $ticketType, $secondsByPriority, $confirmed): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sla-policy:'.$ticketType]);
            }

            $created = [];

            foreach (self::PRIORITIES as $priority) {
                $current = SlaPolicy::query()
                    ->where('ticket_type', $ticketType)
                    ->where('priority', $priority)
                    ->lockForUpdate()
                    ->orderByDesc('version')
                    ->first();

                if ($current && ! $confirmed) {
                    throw ValidationException::withMessages([
                        'ticket_type' => 'Dữ liệu SLA vừa thay đổi. Vui lòng gửi lại để kiểm tra phiên bản trùng.',
                    ]);
                }

                $policy = SlaPolicy::query()->create([
                    'ticket_type' => $ticketType,
                    'priority' => $priority,
                    'version' => $current ? $current->version + 1 : 1,
                    ...$secondsByPriority[$priority],
                ]);

                $this->auditService->record(
                    $request,
                    $current ? 'sla_policy.version_created' : 'sla_policy.created',
                    'sla_policy',
                    $policy->id,
                    $current ? $this->snapshot($current) : null,
                    $this->snapshot($policy),
                );

                $created[] = $policy;
            }

            return $created;
        });

        return redirect()
            ->route('admin.sla-policies.index')
            ->with('success', 'Đã tạo thành công '.count($created).' bộ SLA cho ticket type '.$ticketType.'.');
    }

    public function update(Request $request, SlaPolicy $policy): RedirectResponse
    {
        $validated = $this->validatePolicy($request, false);
        $seconds = $this->toSeconds($validated);
        $this->ensureValidBudget($seconds);

        $newPolicy = DB::transaction(function () use ($request, $policy, $seconds): SlaPolicy {
            $current = SlaPolicy::query()
                ->where('ticket_type', $policy->ticket_type)
                ->where('priority', $policy->priority)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->firstOrFail();

            $newPolicy = SlaPolicy::query()->create([
                'ticket_type' => $current->ticket_type,
                'priority' => $current->priority,
                'version' => $current->version + 1,
                ...$seconds,
            ]);

            $this->auditService->record(
                $request,
                'sla_policy.version_created',
                'sla_policy',
                $newPolicy->id,
                $this->snapshot($current),
                $this->snapshot($newPolicy),
            );

            return $newPolicy;
        });

        return redirect()->route('admin.sla-policies.index')->with('success', "Đã tạo SLA policy phiên bản {$newPolicy->version}.");
    }

    public function history(SlaPolicy $policy): JsonResponse
    {
        $versions = SlaPolicy::query()
            ->where('ticket_type', $policy->ticket_type)
            ->where('priority', $policy->priority)
            ->orderByDesc('version')
            ->get()
            ->map(fn (SlaPolicy $version): array => $this->snapshot($version));

        return response()->json(['data' => $versions]);
    }

    private function validatePolicy(Request $request, bool $creating): array
    {
        return $request->validate([
            'ticket_type' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'priority' => [$creating ? 'required' : 'sometimes', Rule::in(['Urgent', 'High', 'Medium', 'Low'])],
            'total' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'L1' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'L2' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'L3' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'L4' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'RT' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
    }

    private function validateBatch(Request $request): array
    {
        $rules = [
            'ticket_type' => ['required', 'string', 'max:100'],
            'policies' => ['required', 'array'],
            'confirm_versions' => ['nullable', 'boolean'],
        ];

        foreach (self::PRIORITIES as $priority) {
            $rules["policies.{$priority}"] = ['required', 'array'];

            foreach (['total', 'L1', 'L2', 'L3', 'L4', 'RT'] as $field) {
                $rules["policies.{$priority}.{$field}"] = ['required', 'numeric', 'min:0', 'max:1000000'];
            }
        }

        return $request->validate($rules);
    }

    private function toSeconds(array $values): array
    {
        return [
            'total_seconds' => (int) round((float) $values['total'] * 3600),
            'l1_seconds' => (int) round((float) $values['L1'] * 3600),
            'l2_seconds' => (int) round((float) $values['L2'] * 3600),
            'l3_seconds' => (int) round((float) $values['L3'] * 3600),
            'l4_seconds' => (int) round((float) $values['L4'] * 3600),
            'rt_seconds' => (int) round((float) $values['RT'] * 3600),
        ];
    }

    private function ensureValidBudget(array $seconds, string $errorKey = 'total'): void
    {
        $allocated = $seconds['l1_seconds'] + $seconds['l2_seconds'] + $seconds['l3_seconds'] + $seconds['l4_seconds'];

        if ($seconds['total_seconds'] !== $allocated) {
            throw ValidationException::withMessages([
                $errorKey => 'Tổng TTR phải bằng L1 + L2 + L3 + L4.',
            ]);
        }
    }

    private function snapshot(SlaPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'ticket_type' => $policy->ticket_type,
            'priority' => $policy->priority,
            'version' => $policy->version,
            'total' => $policy->total_seconds / 3600,
            'L1' => $policy->l1_seconds / 3600,
            'L2' => $policy->l2_seconds / 3600,
            'L3' => $policy->l3_seconds / 3600,
            'L4' => $policy->l4_seconds / 3600,
            'RT' => $policy->rt_seconds / 3600,
            'created_at' => $policy->created_at?->toIso8601String(),
        ];
    }
}
