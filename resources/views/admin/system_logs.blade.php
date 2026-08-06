@extends('admin.layout')

@section('title', 'Nhật ký lỗi hệ thống')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Nhật ký lỗi hệ thống</h1>
            <p class="text-xs text-slate-500 mt-1">Đọc và kiểm tra chi tiết các tệp ghi nhận nhật ký lỗi ứng dụng</p>
        </div>

        @if($selectedFile)
            <a href="{{ route('admin.system_logs.download', ['file' => $selectedFile]) }}" class="bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-download"></i>
                <span>Tải nhật ký lỗi ({{ $selectedFile }})</span>
            </a>
        @endif
    </div>


    <!-- File Selector Bar -->
    <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex items-center justify-between">
        <form action="{{ route('admin.system_logs') }}" method="GET" class="flex items-center space-x-3">
            <label class="text-xs font-bold text-slate-700">Chọn Tệp Log:</label>
            <select name="file" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-mono font-semibold">
                @foreach($files as $file)
                    <option value="{{ $file }}" {{ $selectedFile == $file ? 'selected' : '' }}>{{ $file }}</option>
                @endforeach
            </select>
        </form>

        <span class="text-xs text-slate-500">Hiển thị 300 dòng mới nhất (Đảo ngược theo thứ tự thời gian)</span>
    </div>

    <!-- Terminal Log Viewer Box -->
    <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 shadow-xl font-mono text-xs overflow-x-auto max-h-[600px] overflow-y-auto leading-relaxed border-l-4 border-l-sky-600">
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
                <span>Tệp log `{{ $selectedFile }}` đang rỗng hoặc chưa có nội dung ghi nhận.</span>
            </div>
        @endforelse
    </div>
</div>
@endsection

