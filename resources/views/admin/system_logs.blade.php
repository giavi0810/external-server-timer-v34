@extends('admin.layout')

@section('title', 'Nhật ký lỗi hệ thống')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Nhật ký lỗi hệ thống</h1>
            <p class="text-xs text-slate-500 mt-1">Đọc và kiểm tra chi tiết các tệp ghi nhận nhật ký lỗi ứng dụng</p>
        </div>

        <div class="flex items-center space-x-2.5">
            <!-- Refresh Button -->
            <a href="{{ route('admin.system_logs', ['file' => $selectedFile, 'hours' => $hours]) }}" class="bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold px-3 py-2 rounded-lg border border-slate-200 transition-colors flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-rotate text-sky-600"></i>
                <span>Làm mới log</span>
            </a>

            <!-- Auto Refresh Control -->
            <div class="flex items-center space-x-1.5 bg-white border border-slate-200/80 rounded-lg px-2.5 py-1.5 shadow-xs text-xs">
                <i class="fa-solid fa-clock-rotate-left text-slate-400"></i>
                <label for="auto-refresh-logs" class="text-slate-600 font-semibold hidden md:inline text-[11px]">Tự động:</label>
                <select id="auto-refresh-logs" onchange="changeAutoRefreshLogs(this.value)" class="bg-transparent text-slate-800 text-[11px] font-bold focus:outline-none cursor-pointer">
                    <option value="0">Tắt</option>
                    <option value="15">15 giây</option>
                    <option value="30">30 giây</option>
                    <option value="60">60 giây</option>
                </select>
                <span id="countdown-logs" class="text-[10px] font-mono font-bold text-sky-600 bg-sky-50 px-1.5 py-0.5 rounded hidden"></span>
            </div>

            <!-- Download Log Button -->
            @if($selectedFile)
                <a href="{{ route('admin.system_logs.download', ['file' => $selectedFile]) }}" class="bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors flex items-center space-x-1.5 shadow-xs">
                    <i class="fa-solid fa-download"></i>
                    <span class="hidden sm:inline">Tải tệp log</span>
                </a>
            @endif
        </div>
    </div>


    <!-- File & Time Selector Bar -->
    <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <form action="{{ route('admin.system_logs') }}" method="GET" class="flex flex-wrap items-center gap-3">
            <div class="flex items-center space-x-2">
                <label class="text-xs font-bold text-slate-700">Chọn Tệp Log:</label>
                <select name="file" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-mono font-semibold">
                    @foreach($files as $file)
                        <option value="{{ $file }}" {{ $selectedFile == $file ? 'selected' : '' }}>{{ $file }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center space-x-2 border-l border-slate-200 pl-3">
                <label class="text-xs font-bold text-slate-700">Khung thời gian:</label>
                <select name="hours" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
                    <option value="1" {{ $hours == 1 ? 'selected' : '' }}>1 giờ gần nhất</option>
                    <option value="6" {{ $hours == 6 ? 'selected' : '' }}>6 giờ gần nhất (Mặc định)</option>
                    <option value="12" {{ $hours == 12 ? 'selected' : '' }}>12 giờ gần nhất</option>
                    <option value="24" {{ $hours == 24 ? 'selected' : '' }}>24 giờ gần nhất</option>
                    <option value="0" {{ $hours == 0 ? 'selected' : '' }}>Tất cả dòng (Tối đa 1.000 dòng)</option>
                </select>
            </div>
        </form>

        <div class="text-xs text-slate-500 flex items-center space-x-2">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-sky-50 text-sky-700 border border-sky-200">
                <i class="fa-solid fa-clock text-sky-500 mr-1.5"></i>
                @if($hours > 0)
                    Hiển thị log {{ $hours }} tiếng gần nhất ({{ count($logContent) }} dòng)
                @else
                    Hiển thị tất cả log ({{ count($logContent) }} dòng)
                @endif
            </span>
        </div>
    </div>

    <!-- Terminal Log Viewer Box -->
    <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 shadow-xl font-mono text-xs overflow-x-auto max-h-[650px] overflow-y-auto leading-relaxed border-l-4 border-l-sky-600">
        @forelse($logContent as $index => $line)
            @php
                $colorClass = 'text-slate-300';
                if (str_contains($line, 'ERROR') || str_contains($line, 'CRITICAL') || str_contains($line, 'EMERGENCY')) {
                    $colorClass = 'text-rose-400 font-bold bg-rose-950/40 px-1 py-0.5 rounded';
                } elseif (str_contains($line, 'WARNING')) {
                    $colorClass = 'text-amber-300 font-bold';
                } elseif (str_contains($line, 'INFO')) {
                    $colorClass = 'text-sky-300';
                } elseif (str_contains($line, 'DEBUG')) {
                    $colorClass = 'text-emerald-400';
                }
            @endphp
            <div class="py-0.5 border-b border-slate-900/50 hover:bg-slate-900/60 {{ $colorClass }}">
                <span class="text-slate-600 select-none mr-2">[{{ sprintf('%03d', $index + 1) }}]</span>
                <span>{{ $line }}</span>
            </div>
        @empty
            <div class="text-center py-12 text-slate-500 font-sans">
                <i class="fa-solid fa-file-circle-xmark text-3xl mb-2 block text-slate-600"></i>
                <span>Không có nhật ký ghi nhận nào trong {{ $hours > 0 ? $hours . ' giờ gần nhất' : 'tệp log này' }}.</span>
            </div>
        @endforelse
    </div>
</div>

<script>
    let logAutoRefreshInterval = null;
    let logCountdownSeconds = 0;

    function changeAutoRefreshLogs(seconds) {
        seconds = parseInt(seconds);
        const countdownEl = document.getElementById('countdown-logs');

        if (logAutoRefreshInterval) {
            clearInterval(logAutoRefreshInterval);
            logAutoRefreshInterval = null;
        }

        if (seconds > 0) {
            logCountdownSeconds = seconds;
            countdownEl.classList.remove('hidden');
            countdownEl.innerText = logCountdownSeconds + 's';

            logAutoRefreshInterval = setInterval(() => {
                logCountdownSeconds--;
                if (logCountdownSeconds <= 0) {
                    logCountdownSeconds = seconds;
                    window.location.reload();
                }
                countdownEl.innerText = logCountdownSeconds + 's';
            }, 1000);
        } else {
            countdownEl.classList.add('hidden');
        }
    }
</script>
@endsection
