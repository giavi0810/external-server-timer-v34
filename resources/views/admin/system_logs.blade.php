@extends('admin.layout')

@section('title', 'Tra cứu nhật ký hệ thống')

@section('content')
@php
    $statistics = $result['statistics'];
    $criticalCount = ($statistics['EMERGENCY'] ?? 0) + ($statistics['ALERT'] ?? 0) + ($statistics['CRITICAL'] ?? 0);
    $errorCount = $statistics['ERROR'] ?? 0;
    $warningCount = $statistics['WARNING'] ?? 0;
    $infoCount = ($statistics['NOTICE'] ?? 0) + ($statistics['INFO'] ?? 0) + ($statistics['DEBUG'] ?? 0);
    $componentLabels = [
        'freshdesk' => 'Freshdesk',
        'sla' => 'SLA',
        'queue' => 'Queue',
        'redis' => 'Redis',
        'postgresql' => 'PostgreSQL',
        'rocketchat' => 'Rocket.Chat',
        'application' => 'Ứng dụng',
    ];
    $levelClasses = [
        'EMERGENCY' => 'bg-rose-100 text-rose-800 border-rose-200',
        'ALERT' => 'bg-rose-100 text-rose-800 border-rose-200',
        'CRITICAL' => 'bg-rose-100 text-rose-800 border-rose-200',
        'ERROR' => 'bg-red-50 text-red-700 border-red-200',
        'WARNING' => 'bg-amber-50 text-amber-700 border-amber-200',
        'NOTICE' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'INFO' => 'bg-sky-50 text-sky-700 border-sky-200',
        'DEBUG' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    ];
    $scannedSize = $result['scanned_bytes'] >= 1048576
        ? number_format($result['scanned_bytes'] / 1048576, 2).' MB'
        : number_format($result['scanned_bytes'] / 1024, 2).' KB';
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Tra cứu nhật ký hệ thống</h1>
            <p class="mt-1 text-xs text-slate-500">Chọn một tệp log theo ngày, sau đó tìm theo mã, thành phần và giờ thực tế trong ngày đó.</p>
        </div>

        <div class="flex items-center gap-2.5">
            <a id="log-refresh-button" href="{{ route('admin.system_logs', request()->query()) }}" onclick="return requestLogRefresh(event)" class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-xs transition-colors hover:bg-slate-50">
                <i class="fa-solid fa-rotate text-sky-600"></i>
                <span id="log-refresh-label">Làm mới log</span>
            </a>

            <div class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs shadow-xs">
                <i class="fa-solid fa-clock-rotate-left text-slate-400"></i>
                <select id="auto-refresh-logs" onchange="changeAutoRefreshLogs(this.value)" class="cursor-pointer bg-transparent text-[11px] font-bold text-slate-800 focus:outline-none">
                    <option value="0">Tự động: Tắt</option>
                    <option value="15">15 giây</option>
                    <option value="30">30 giây</option>
                    <option value="60">60 giây</option>
                </select>
                <span id="countdown-logs" class="hidden rounded bg-sky-50 px-1.5 py-0.5 font-mono text-[10px] font-bold text-sky-600"></span>
            </div>

            @if($selectedFile)
                <a href="{{ route('admin.system_logs.download', ['file' => $selectedFile]) }}" class="flex items-center gap-1.5 rounded-lg bg-sky-600 px-3.5 py-2 text-xs font-semibold text-white shadow-xs transition-colors hover:bg-sky-700">
                    <i class="fa-solid fa-download"></i>
                    <span class="hidden sm:inline">Tải tệp log</span>
                </a>
            @endif
        </div>
    </div>

    @if($sourceIsStale)
        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
            <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
            <div class="text-xs leading-relaxed">
                <strong>Nguồn log không còn cập nhật.</strong>
                @if($latestTimestamp)
                    Lần cập nhật gần nhất: {{ $latestTimestamp->format('d/m/Y H:i:s') }}.
                @else
                    Không tìm thấy tệp log ứng dụng.
                @endif
                Hãy kiểm tra `LOG_CHANNEL` và log stdout/stderr của các Pod.
            </div>
        </div>
    @endif

    <form action="{{ route('admin.system_logs') }}" method="GET" class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">
            <div class="lg:col-span-5">
                <label for="log-query" class="mb-1.5 block text-xs font-bold text-slate-700">Tìm kiếm</label>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-400"></i>
                    <input id="log-query" name="query" value="{{ $query }}" maxlength="200" placeholder="ticket_id:18606, receipt_id:..., exception hoặc nội dung" class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-xs text-slate-800 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                </div>
            </div>

            <div class="lg:col-span-3">
                <label for="log-file" class="mb-1.5 block text-xs font-bold text-slate-700">Nguồn log</label>
                <select id="log-file" name="file" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-800 outline-none focus:border-sky-400">
                    @foreach($files as $file)
                        <option value="{{ $file }}" {{ $selectedFile === $file ? 'selected' : '' }}>
                            {{ $fileMetadata[$file]['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="log-level" class="mb-1.5 block text-xs font-bold text-slate-700">Mức độ</label>
                <select id="log-level" name="level" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800 outline-none focus:border-sky-400">
                    <option value="" {{ $level === '' ? 'selected' : '' }}>Tất cả mức độ</option>
                    <option value="ERRORS" {{ $level === 'ERRORS' ? 'selected' : '' }}>Chỉ lỗi nghiêm trọng</option>
                    @foreach(['CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'] as $levelOption)
                        <option value="{{ $levelOption }}" {{ $level === $levelOption ? 'selected' : '' }}>{{ $levelOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="log-component" class="mb-1.5 block text-xs font-bold text-slate-700">Thành phần</label>
                <select id="log-component" name="component" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800 outline-none focus:border-sky-400">
                    <option value="">Tất cả thành phần</option>
                    @foreach($componentLabels as $componentKey => $componentLabel)
                        <option value="{{ $componentKey }}" {{ $component === $componentKey ? 'selected' : '' }}>{{ $componentLabel }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 md:grid-cols-3">
            <div>
                <label for="log-time-from" class="mb-1.5 block text-xs font-bold text-slate-700">Từ giờ</label>
                <input id="log-time-from" type="time" step="1" name="time_from" value="{{ $timeFrom }}" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800 outline-none focus:border-sky-400">
            </div>
            <div>
                <label for="log-time-to" class="mb-1.5 block text-xs font-bold text-slate-700">Đến giờ</label>
                <input id="log-time-to" type="time" step="1" name="time_to" value="{{ $timeTo }}" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800 outline-none focus:border-sky-400">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-sky-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-sky-700">
                    <i class="fa-solid fa-magnifying-glass"></i> Tra cứu
                </button>
                <a href="{{ route('admin.system_logs') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Xóa lọc</a>
            </div>
        </div>
    </form>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3"><div class="text-[10px] font-bold uppercase text-rose-500">Critical</div><div class="mt-1 font-mono text-xl font-extrabold text-rose-700">{{ number_format($criticalCount) }}</div></div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-3"><div class="text-[10px] font-bold uppercase text-red-500">Error</div><div class="mt-1 font-mono text-xl font-extrabold text-red-700">{{ number_format($errorCount) }}</div></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3"><div class="text-[10px] font-bold uppercase text-amber-500">Warning</div><div class="mt-1 font-mono text-xl font-extrabold text-amber-700">{{ number_format($warningCount) }}</div></div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3"><div class="text-[10px] font-bold uppercase text-sky-500">Info/Debug</div><div class="mt-1 font-mono text-xl font-extrabold text-sky-700">{{ number_format($infoCount) }}</div></div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
        <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm font-bold text-slate-800">Kết quả tra cứu</div>
            <div class="text-xs text-slate-500">
                {{ number_format($result['total']) }} kết quả · Đã quét toàn bộ {{ $scannedSize }} · 50 kết quả/trang · Trang {{ $result['page'] }}/{{ $result['last_page'] }}
            </div>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($result['entries'] as $index => $entry)
                <details class="group">
                    <summary class="cursor-pointer list-none px-4 py-3 transition-colors hover:bg-slate-50">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-chevron-right mt-1 text-[10px] text-slate-400 transition-transform group-open:rotate-90"></i>
                            <div class="min-w-0 flex-1">
                                <div class="mb-1.5 flex flex-wrap items-center gap-2">
                                    <span class="rounded border px-2 py-0.5 font-mono text-[10px] font-bold {{ $levelClasses[$entry['level']] ?? 'border-slate-200 bg-slate-50 text-slate-600' }}">{{ $entry['level'] }}</span>
                                    <span class="font-mono text-[11px] text-slate-500">{{ $entry['timestamp'] }}</span>
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">{{ $componentLabels[$entry['component']] ?? $entry['component'] }}</span>
                                    <span class="font-mono text-[10px] text-slate-400">{{ $entry['source'] }}</span>
                                </div>
                                <div class="break-words text-xs font-semibold leading-relaxed text-slate-800">{{ $entry['message'] }}</div>
                                @if($entry['identifiers'] !== [])
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach($entry['identifiers'] as $identifier => $value)
                                            <span class="rounded border border-sky-200 bg-sky-50 px-2 py-0.5 font-mono text-[10px] text-sky-700">{{ $identifier }}:{{ $value }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </summary>
                    <div class="border-t border-slate-100 bg-slate-950 p-4">
                        <div class="mb-2 flex justify-end">
                            <button type="button" onclick="copyLogEntry('log-entry-{{ $index }}', this)" class="rounded border border-slate-700 bg-slate-900 px-2.5 py-1 text-[10px] font-bold text-slate-300 hover:bg-slate-800">
                                <i class="fa-regular fa-copy mr-1"></i> Sao chép
                            </button>
                        </div>
                        <pre id="log-entry-{{ $index }}" class="whitespace-pre-wrap break-words font-mono text-[11px] leading-relaxed text-slate-300">{{ $entry['details'] }}</pre>
                    </div>
                </details>
            @empty
                <div class="px-6 py-16 text-center text-slate-500">
                    <i class="fa-solid fa-file-circle-xmark mb-3 block text-3xl text-slate-300"></i>
                    <div class="text-sm font-bold text-slate-700">Không tìm thấy bản ghi phù hợp</div>
                    <div class="mt-1 text-xs">Hãy kiểm tra nguồn log, khoảng thời gian hoặc điều kiện tìm kiếm.</div>
                </div>
            @endforelse
        </div>

        @if($result['total'] > $result['per_page'])
            <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3">
                @if($result['page'] > 1)
                    <a href="{{ route('admin.system_logs', array_merge(request()->except('page'), ['page' => $result['page'] - 1])) }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">← Trang trước</a>
                @else
                    <span></span>
                @endif

                @if($result['page'] < $result['last_page'])
                    <a href="{{ route('admin.system_logs', array_merge(request()->except('page'), ['page' => $result['page'] + 1])) }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">Trang sau →</a>
                @endif
            </div>
        @endif
    </div>
</div>

<script>
    let logAutoRefreshInterval = null;
    let logCountdownSeconds = 0;
    const logRefreshCooldownKey = 'admin-system-log-refresh-unlock-at';
    const logRefreshCooldownMs = 5000;
    let logRefreshCooldownTimer = null;

    function requestLogRefresh(event) {
        const unlockAt = Number(sessionStorage.getItem(logRefreshCooldownKey) || 0);
        if (Date.now() < unlockAt) {
            event.preventDefault();
            updateLogRefreshButton();
            return false;
        }
        sessionStorage.setItem(logRefreshCooldownKey, String(Date.now() + logRefreshCooldownMs));
        return true;
    }

    function updateLogRefreshButton() {
        const button = document.getElementById('log-refresh-button');
        const label = document.getElementById('log-refresh-label');
        if (!button || !label) return;

        const unlockAt = Number(sessionStorage.getItem(logRefreshCooldownKey) || 0);
        const remainingMs = Math.max(0, unlockAt - Date.now());
        if (remainingMs > 0) {
            button.setAttribute('aria-disabled', 'true');
            button.classList.add('opacity-60');
            label.textContent = `Làm mới sau ${Math.ceil(remainingMs / 1000)}s`;
            logRefreshCooldownTimer = window.setTimeout(updateLogRefreshButton, 100);
            return;
        }

        sessionStorage.removeItem(logRefreshCooldownKey);
        button.removeAttribute('aria-disabled');
        button.classList.remove('opacity-60');
        label.textContent = 'Làm mới log';
        if (logRefreshCooldownTimer) window.clearTimeout(logRefreshCooldownTimer);
    }

    function changeAutoRefreshLogs(seconds) {
        seconds = parseInt(seconds);
        const countdownEl = document.getElementById('countdown-logs');
        if (logAutoRefreshInterval) clearInterval(logAutoRefreshInterval);

        if (seconds > 0) {
            logCountdownSeconds = seconds;
            countdownEl.classList.remove('hidden');
            countdownEl.innerText = logCountdownSeconds + 's';
            logAutoRefreshInterval = setInterval(() => {
                logCountdownSeconds--;
                if (logCountdownSeconds <= 0) window.location.reload();
                countdownEl.innerText = logCountdownSeconds + 's';
            }, 1000);
        } else {
            countdownEl.classList.add('hidden');
        }
    }

    async function copyLogEntry(elementId, button) {
        const element = document.getElementById(elementId);
        if (!element) return;
        await navigator.clipboard.writeText(element.innerText);
        const original = button.innerHTML;
        button.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Đã sao chép';
        setTimeout(() => button.innerHTML = original, 1500);
    }

    updateLogRefreshButton();
</script>
@endsection
