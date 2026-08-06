@extends('admin.layout')

@section('title', 'Nhật ký gửi cảnh báo')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Nhật ký gửi cảnh báo</h1>
            <p class="text-xs text-slate-500 mt-1">Theo dõi chi tiết lịch sử gửi tin nhắn cảnh báo sự cố sang kênh Rocket.Chat</p>
        </div>
        <div>
            <a href="{{ route('admin.rocketchat_audit.export', request()->query()) }}" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-file-csv text-base"></i>
                <span>Xuất CSV</span>
            </a>
        </div>
    </div>

    @if(isset($dbError) && $dbError)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start space-x-3 shadow-xs">
            <i class="fa-solid fa-triangle-exclamation text-amber-600 text-lg flex-shrink-0 mt-0.5"></i>
            <div class="text-xs text-amber-900 leading-relaxed min-w-0">
                <strong class="font-bold block text-amber-950 mb-0.5">Không thể kết nối CSDL PostgreSQL!</strong>
                <span>Hiện tại container CSDL đang bị ngắt kết nối. Chi tiết: <code class="bg-amber-100 px-1 py-0.5 rounded text-amber-950 font-mono">{{ $dbError }}</code>. Vui lòng bật lại container <code class="bg-amber-100 px-1 py-0.5 rounded text-amber-950 font-mono">timer-v34-postgres</code> trong Docker.</span>
            </div>
        </div>
    @endif

    <!-- Filter Bar -->
    <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs">
        <form action="{{ route('admin.rocketchat_audit') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-3">
            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Trạng thái</label>
                <select name="status" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="SUCCESS" {{ request('status') == 'SUCCESS' ? 'selected' : '' }}>SUCCESS (Thành công)</option>
                    <option value="FAILED" {{ request('status') == 'FAILED' ? 'selected' : '' }}>FAILED (Thất bại)</option>
                    <option value="UNKNOWN" {{ request('status') == 'UNKNOWN' ? 'selected' : '' }}>UNKNOWN (Không rõ)</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Mã sự cố</label>
                <select name="event_code" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
                    <option value="">-- Tất cả loại sự cố --</option>
                    @foreach($eventCodes as $code)
                        <option value="{{ $code }}" {{ request('event_code') == $code ? 'selected' : '' }}>{{ $code }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Từ ngày</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Đến ngày</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Tìm kiếm mã</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Mã đơn gửi / Message ID..." class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
            </div>

            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors shadow-xs">
                    <i class="fa-solid fa-filter mr-1"></i> Lọc
                </button>
                <a href="{{ route('admin.rocketchat_audit') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold px-3 py-2 rounded-lg border border-slate-200 transition-colors">
                    Đặt lại
                </a>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="bg-white border border-slate-200/80 rounded-xl p-5 shadow-xs">
        <div class="overflow-x-auto border border-slate-200 rounded-lg">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="text-xs text-slate-500 uppercase bg-slate-100/80 border-b border-slate-200 font-bold">
                    <tr>
                        <th class="w-8 px-2 py-3 text-center"></th>
                        <th class="px-4 py-3 whitespace-nowrap">Mã đơn gửi</th>
                        <th class="px-4 py-3 whitespace-nowrap">Mã sự cố</th>
                        <th class="px-4 py-3 whitespace-nowrap">Trạng thái</th>
                        <th class="px-4 py-3 whitespace-nowrap">Mã HTTP</th>
                        <th class="px-4 py-3 whitespace-nowrap">Mã tin nhắn</th>
                        <th class="px-4 py-3 whitespace-nowrap">Số lần thử</th>
                        <th class="px-4 py-3 whitespace-nowrap">Thời gian gửi</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-mono text-xs">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-50 transition-colors cursor-pointer" onclick="toggleDetails('row-{{ $loop->index }}')">
                            <td class="px-2 py-3 text-center text-slate-400">
                                <i id="icon-row-{{ $loop->index }}" class="fa-solid fa-chevron-right transition-transform duration-200 text-xs"></i>
                            </td>
                            <td class="px-4 py-3 font-semibold text-slate-900" title="{{ $log->delivery_id }}">{{ Str::limit($log->delivery_id, 18) }}</td>
                            <td class="px-4 py-3 text-sky-700 font-bold">{{ $log->event_code }}</td>
                            <td class="px-4 py-3">
                                @if($log->status === 'SUCCESS')
                                    <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded font-bold">SUCCESS</span>
                                @elseif($log->status === 'FAILED')
                                    <span class="bg-rose-50 text-rose-700 border border-rose-200 px-2 py-0.5 rounded font-bold">FAILED</span>
                                @else
                                    <span class="bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded font-bold">{{ $log->status }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-medium">{{ $log->http_status ?? '--' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $log->rocketchat_message_id ? Str::limit($log->rocketchat_message_id, 12) : '--' }}</td>
                            <td class="px-4 py-3">{{ $log->attempt_count }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $log->formatted_attempted_at }}</td>
                            <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                                @if($log->status === 'FAILED' || $log->status === 'UNKNOWN')
                                    <form action="{{ route('admin.rocketchat_audit.retry', $log->delivery_id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" onclick="return confirm('Bạn có chắc muốn thử gửi lại cảnh báo này không?')" class="bg-amber-500 hover:bg-amber-600 text-white px-2.5 py-1 rounded text-[11px] font-sans font-semibold transition-colors shadow-xs">
                                            <i class="fa-solid fa-rotate-right mr-1"></i> Gửi lại
                                        </button>
                                    </form>
                                @else
                                    <span class="text-slate-400 text-[11px] font-sans">N/A</span>
                                @endif
                            </td>
                        </tr>
                        <!-- Expandable Details Accordion -->
                        <tr id="details-row-{{ $loop->index }}" class="hidden bg-slate-50/90 font-sans">
                            <td colspan="9" class="p-4 border-l-4 border-sky-600">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                                    <div>
                                        <span class="text-slate-500 font-bold uppercase block mb-1">Mã Lượt Gửi Đầy Đủ (Delivery ID):</span>
                                        <div class="bg-white p-2.5 rounded border border-slate-200 font-mono text-sky-700 break-all select-all shadow-xs">
                                            <span>{{ $log->delivery_id }}</span>
                                        </div>
                                        <span class="text-slate-500 font-bold uppercase block mt-3 mb-1">RocketChat Message ID:</span>
                                        <div class="bg-white p-2.5 rounded border border-slate-200 font-mono text-slate-800 shadow-xs">
                                            {{ $log->rocketchat_message_id ?? 'N/A' }}
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-slate-500 font-bold uppercase block mb-1">Thông Báo Lỗi (Error Details):</span>
                                        <div class="bg-rose-50/60 p-2.5 rounded border border-rose-200 font-mono text-rose-800 min-h-[60px] max-h-36 overflow-y-auto whitespace-pre-wrap">
                                            {{ $log->error_message ?? 'Không có lỗi (Gửi thành công)' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-slate-400 font-sans">
                                @if(isset($dbError) && $dbError)
                                    <div class="max-w-md mx-auto">
                                        <i class="fa-solid fa-triangle-exclamation text-amber-500 text-2xl mb-2"></i>
                                        <h4 class="text-xs font-bold text-slate-700 mb-1">CSDL PostgreSQL hiện đang ngắt kết nối</h4>
                                        <p class="text-[11px] text-slate-400 leading-relaxed">Vui lòng khởi động lại container <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-600 font-mono">timer-v34-postgres</code> để truy vấn nhật ký gửi cảnh báo.</p>
                                    </div>
                                @else
                                    <span>Không tìm thấy bản ghi nhật ký gửi nào phù hợp.</span>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $logs->links() }}
        </div>
    </div>
</div>

<script>
    function toggleDetails(rowId) {
        const detailsRow = document.getElementById('details-' + rowId);
        const icon = document.getElementById('icon-' + rowId);
        if (detailsRow) {
            if (detailsRow.classList.contains('hidden')) {
                detailsRow.classList.remove('hidden');
                if (icon) icon.classList.add('rotate-90');
            } else {
                detailsRow.classList.add('hidden');
                if (icon) icon.classList.remove('rotate-90');
            }
        }
    }
</script>
@endsection

