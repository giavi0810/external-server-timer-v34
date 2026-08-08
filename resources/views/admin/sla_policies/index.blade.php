@extends('admin.layout')

@section('title', 'Quản lý SLA Policy')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Quản lý SLA Policy</h1>
        </div>
        @if($canManage)
            <button type="button" onclick="document.getElementById('create-sla-dialog').showModal()" class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-700">
                <i class="fa-solid fa-plus mr-1"></i> Thêm SLA Policy
            </button>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-left">Ticket type</th>
                    <th class="px-4 py-3 text-left">Priority</th>
                    <th class="px-4 py-3 text-right">TTR</th>
                    <th class="px-4 py-3 text-right">L1</th>
                    <th class="px-4 py-3 text-right">L2</th>
                    <th class="px-4 py-3 text-right">L3</th>
                    <th class="px-4 py-3 text-right">L4</th>
                    <th class="px-4 py-3 text-right">RT</th>
                    <th class="px-4 py-3 text-right">Version</th>
                    <th class="px-4 py-3 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($policies as $policy)
                    <tr>
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $policy->ticket_type }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ $policy->priority }}</span></td>
                        @foreach(['total_seconds', 'l1_seconds', 'l2_seconds', 'l3_seconds', 'l4_seconds', 'rt_seconds'] as $column)
                            <td class="px-4 py-3 text-right font-mono">{{ number_format($policy->{$column} / 3600, 2) }}h</td>
                        @endforeach
                        <td class="px-4 py-3 text-right font-mono">v{{ $policy->version }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="button" data-history-url="{{ route('admin.sla-policies.history', $policy) }}" onclick="showHistory(this.dataset.historyUrl)" class="font-semibold text-slate-600 hover:text-slate-900">Lịch sử</button>
                            @if($canManage)
                                <button type="button" onclick="document.getElementById('edit-sla-{{ $policy->id }}').showModal()" class="ml-3 font-semibold text-sky-700 hover:text-sky-900">Sửa</button>
                            @endif
                        </td>
                    </tr>

                    @if($canManage)
                        <dialog id="edit-sla-{{ $policy->id }}" class="w-full max-w-xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-xs border-0 m-auto">
                            <form action="{{ route('admin.sla-policies.update', $policy) }}" method="POST" class="flex flex-col">
                                @csrf
                                @method('PUT')
                                <!-- Modal Header -->
                                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                                            <i class="fa-solid fa-pen-to-square text-base"></i>
                                        </div>
                                        <div>
                                            <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                                                <span>Sửa {{ $policy->ticket_type }}</span>
                                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $policy->priority === 'Urgent' ? 'bg-rose-100 text-rose-700' : ($policy->priority === 'High' ? 'bg-orange-100 text-orange-700' : ($policy->priority === 'Medium' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700')) }}">
                                                    {{ $policy->priority }}
                                                </span>
                                            </h2>
                                            <p class="text-xs text-slate-500 mt-0.5">Phiên bản hiện tại: v{{ $policy->version }}</p>
                                        </div>
                                    </div>
                                    <button type="button" onclick="this.closest('dialog').close()" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                                        <i class="fa-solid fa-xmark text-lg"></i>
                                    </button>
                                </div>

                                <!-- Modal Body -->
                                <div class="p-6 space-y-5">
                                    @include('admin.sla_policies.partials.fields', ['fieldPolicy' => $policy, 'creating' => false])
                                </div>

                                <!-- Modal Footer -->
                                <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4 rounded-b-2xl">
                                    <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition-colors">
                                        Hủy
                                    </button>
                                    <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-700 transition-colors flex items-center gap-2">
                                        <i class="fa-solid fa-code-commit text-xs"></i>
                                        <span>Tạo version mới</span>
                                    </button>
                                </div>
                            </form>
                        </dialog>
                    @endif
                @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-slate-500">Chưa có SLA policy.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-bold text-slate-900">Audit log SLA gần nhất</h2></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3 text-left">Thời gian</th><th class="px-4 py-3 text-left">Tài khoản</th><th class="px-4 py-3 text-left">Role</th><th class="px-4 py-3 text-left">Thao tác</th><th class="px-4 py-3 text-left">IP</th><th class="px-4 py-3 text-left">Thay đổi</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($auditLogs as $log)
                        <tr><td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td><td class="px-4 py-3 font-mono">{{ $log->username }}</td><td class="px-4 py-3 font-mono">{{ $log->actor_role }}</td><td class="px-4 py-3">{{ $log->action }}</td><td class="px-4 py-3 font-mono">{{ $log->ip_address }}</td><td class="px-4 py-3"><details><summary class="cursor-pointer text-sky-700">Xem JSON</summary><pre class="mt-2 max-w-xl overflow-auto rounded bg-slate-950 p-3 text-xs text-emerald-300">{{ json_encode(['old' => $log->old_values, 'new' => $log->new_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Chưa có audit log SLA.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($canManage)
<dialog id="create-sla-dialog" class="w-full max-w-6xl rounded-2xl p-0 backdrop:bg-slate-900/50">
    <form id="create-sla-batch-form" action="{{ route('admin.sla-policies.store') }}" method="POST" class="max-h-[90vh] overflow-y-auto p-6 space-y-5">
        @csrf
        <div class="flex justify-between"><div><h2 class="text-lg font-bold">Thêm bộ SLA Policy</h2></div><button type="button" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button></div>
        @include('admin.sla_policies.partials.batch_fields')
        <div class="sticky bottom-0 flex justify-end gap-3 border-t border-slate-200 bg-white pt-4"><button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border px-4 py-2 text-sm font-semibold">Hủy</button><button class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white">Tạo 4 policy</button></div>
    </form>
</dialog>
@endif

@if(session('sla_batch_warning'))
<dialog id="sla-duplicate-warning-dialog" class="w-full max-w-xl rounded-2xl p-0 backdrop:bg-slate-900/50">
    <div class="p-6">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <h2 class="text-lg font-bold text-slate-900">Phát hiện SLA Policy bị trùng</h2>
                <p class="mt-1 text-sm text-slate-600">Ticket type <strong>{{ session('sla_batch_warning.ticket_type') }}</strong> đã có cấu hình cho các mức ưu tiên sau:</p>
            </div>
        </div>
        <div class="mt-4 overflow-hidden rounded-xl border border-amber-200 bg-amber-50">
            @foreach(session('sla_batch_warning.duplicates', []) as $duplicate)
                <div class="flex items-center justify-between border-b border-amber-200 px-4 py-3 text-sm last:border-b-0">
                    <span class="font-semibold text-amber-900">{{ $duplicate['priority'] }}</span>
                    <span class="font-mono text-amber-800">v{{ $duplicate['current_version'] }} → v{{ $duplicate['next_version'] }}</span>
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-sm text-slate-600">Nếu xác nhận, các bộ bị trùng sẽ được tạo thành version mới; các bộ chưa tồn tại sẽ được tạo ở version 1. Không có dữ liệu nào được ghi trước khi xác nhận.</p>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" onclick="cancelBatchVersionConfirmation()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Hủy</button>
            <button type="button" onclick="confirmBatchVersions()" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Xác nhận tạo version mới</button>
        </div>
    </div>
</dialog>
@endif

<dialog id="history-dialog" class="w-full max-w-3xl rounded-2xl p-0 backdrop:bg-slate-900/50">
    <div class="p-6"><div class="mb-4 flex justify-between"><h2 class="text-lg font-bold">Lịch sử phiên bản SLA</h2><button onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button></div><div id="history-content" class="text-sm text-slate-600">Đang tải...</div></div>
</dialog>

<script>
function confirmBatchVersions() {
    const form = document.getElementById('create-sla-batch-form');
    let confirmation = form.querySelector('input[name="confirm_versions"]');

    if (!confirmation) {
        confirmation = document.createElement('input');
        confirmation.type = 'hidden';
        confirmation.name = 'confirm_versions';
        form.appendChild(confirmation);
    }

    confirmation.value = '1';
    document.getElementById('sla-duplicate-warning-dialog')?.close();
    form.requestSubmit();
}

function cancelBatchVersionConfirmation() {
    document.getElementById('sla-duplicate-warning-dialog')?.close();
    document.getElementById('create-sla-dialog')?.close();
}

@if(session('sla_batch_warning'))
document.getElementById('sla-duplicate-warning-dialog').showModal();
@elseif($errors->any() && old('policies'))
document.getElementById('create-sla-dialog')?.showModal();
@endif

async function showHistory(url) {
    const dialog = document.getElementById('history-dialog');
    const content = document.getElementById('history-content');
    content.textContent = 'Đang tải...';
    dialog.showModal();
    try {
        const response = await fetch(url, {headers: {'Accept': 'application/json'}});
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const payload = await response.json();
        content.innerHTML = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200"><thead><tr><th class="p-2 text-left">Version</th><th class="p-2 text-left">TTR</th><th class="p-2 text-left">L1/L2/L3/L4</th><th class="p-2 text-left">RT</th><th class="p-2 text-left">Thời gian</th></tr></thead><tbody>' + payload.data.map(item => `<tr class="border-t"><td class="p-2 font-mono">v${item.version}</td><td class="p-2">${item.total}h</td><td class="p-2 font-mono">${item.L1}/${item.L2}/${item.L3}/${item.L4}h</td><td class="p-2">${item.RT}h</td><td class="p-2">${item.created_at ?? '—'}</td></tr>`).join('') + '</tbody></table></div>';
    } catch (error) {
        content.textContent = 'Không thể tải lịch sử: ' + error.message;
    }
}
</script>
@endsection
