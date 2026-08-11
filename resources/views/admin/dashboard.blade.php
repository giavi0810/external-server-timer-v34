@extends('admin.layout')

@section('title', 'Trang tổng quan')

@section('content')
<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Trang tổng quan</h1>
            <p class="text-xs text-slate-500 mt-1">Theo dõi hàng chờ xử lý, lịch sử gửi cảnh báo và trạng thái dịch vụ</p>
        </div>
        <div class="flex items-center space-x-3 flex-shrink-0">
            <!-- Auto Refresh Control -->
            <div class="flex items-center space-x-2 bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-600 shadow-xs">
                <i class="fa-solid fa-clock text-sky-600"></i>
                <span class="whitespace-nowrap font-medium">Tự động làm mới:</span>
                <select id="auto-refresh-select" onchange="changeAutoRefresh(this.value)" class="bg-slate-50 border border-slate-200 text-slate-800 rounded px-2 py-0.5 focus:outline-none focus:ring-1 focus:ring-sky-500 font-semibold">
                    <option value="0">Tắt</option>
                    <option value="30">30s</option>
                    <option value="60">60s</option>
                </select>
                <span id="auto-refresh-countdown" class="hidden text-[11px] font-mono text-amber-700 font-bold bg-amber-50 px-2 py-0.5 rounded border border-amber-200">30s</span>
            </div>

            <a id="dashboard-refresh-button" href="{{ route('admin.dashboard') }}" onclick="return requestDashboardRefresh(event)" class="bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 transition-colors flex items-center space-x-1.5 shadow-xs whitespace-nowrap">
                <i class="fa-solid fa-rotate text-sky-600"></i>
                <span id="dashboard-refresh-label">Làm mới dữ liệu</span>
            </a>
        </div>
    </div>

    @if($dbStatus !== 'OK')
        <!-- DB Offline Global Warning Banner -->
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start space-x-3 shadow-xs">
            <i class="fa-solid fa-triangle-exclamation text-amber-600 text-lg flex-shrink-0 mt-0.5"></i>
            <div class="text-xs text-amber-900 leading-relaxed min-w-0">
                <strong class="font-bold block text-amber-950 mb-0.5">CẢNH BÁO: Kết nối CSDL PostgreSQL hiện đang bị ngắt!</strong>
                <span>Tiến trình nhận sự cố qua đĩa đệm và đọc File Log hệ thống vẫn tiếp tục hoạt động an toàn. Vui lòng bật lại container PostgreSQL (<code class="bg-amber-100 px-1 py-0.5 rounded text-amber-950 font-mono">timer-v34-postgres</code>) để khôi phục lưu trữ nhật ký CSDL.</span>
            </div>
        </div>
    @endif

    <!-- Top KPI Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <!-- Spool Ready Card -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between space-x-2">
                <div class="min-w-0">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block truncate cursor-default" title="Cảnh báo sự cố chờ lưu lịch sử vào CSDL">Cảnh báo lưu</span>
                    <span class="text-3xl font-extrabold text-emerald-600 mt-1 block font-mono">{{ $spoolCounts['ready'] }}</span>
                </div>
                <div class="w-10 h-10 bg-emerald-50 border border-emerald-100 rounded-xl flex-shrink-0 flex items-center justify-center text-emerald-600">
                    <i class="fa-solid fa-clock-rotate-left text-base"></i>
                </div>
            </div>
            <span class="text-[11px] text-slate-500 mt-2 block truncate" title="Tin nhắn cảnh báo chờ lưu lịch sử">Tin nhắn chờ lưu lịch sử</span>
        </div>

        <!-- Freshdesk Spool Ready Card -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between space-x-2">
                <div class="min-w-0">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block truncate cursor-default" title="Thông báo sự cố Freshdesk chờ vào hàng chờ">Sự cố mới</span>
                    <span class="text-3xl font-extrabold text-indigo-600 mt-1 block font-mono">{{ $freshdeskSpoolCounts['ready'] }}</span>
                </div>
                <div class="w-10 h-10 bg-indigo-50 border border-indigo-100 rounded-xl flex-shrink-0 flex items-center justify-center text-indigo-600">
                    <i class="fa-solid fa-ticket-simple text-base"></i>
                </div>
            </div>
            <span class="text-[11px] text-slate-500 mt-2 block truncate" title="Thông báo sự cố chờ đưa vào hàng chờ">Sự cố chờ vào hàng chờ</span>
        </div>

        <!-- RocketChat Success 24h Card -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between space-x-2">
                <div class="min-w-0">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block truncate cursor-default" title="Số cảnh báo đã gửi thành công trong 24 giờ qua">Thành công (24h)</span>
                    @if($dbStatus === 'OK' && isset($deliveryStats))
                        <span class="text-3xl font-extrabold text-sky-600 mt-1 block font-mono">{{ $deliveryStats['success_24h'] }}</span>
                    @else
                        <span class="text-2xl font-extrabold text-slate-400 mt-1 block font-mono">--</span>
                    @endif
                </div>
                <div class="w-10 h-10 bg-sky-50 border border-sky-100 rounded-xl flex-shrink-0 flex items-center justify-center text-sky-600">
                    <i class="fa-solid fa-circle-check text-base"></i>
                </div>
            </div>
            @if($dbStatus === 'OK')
                <span class="text-[11px] text-slate-500 mt-2 block truncate">Đã gửi thành công</span>
            @else
                <span class="text-[11px] text-rose-500 font-bold mt-2 block truncate" title="{{ $dbStatus }}">N/A (CSDL Offline)</span>
            @endif
        </div>

        <!-- RocketChat Failed 24h Card -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between space-x-2">
                <div class="min-w-0">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block truncate cursor-default" title="Số cảnh báo gửi Rocket.Chat thất bại trong 24 giờ qua">Thất bại (24h)</span>
                    @if($dbStatus === 'OK' && isset($deliveryStats))
                        <span class="text-3xl font-extrabold text-rose-600 mt-1 block font-mono">{{ $deliveryStats['failed_24h'] }}</span>
                    @else
                        <span class="text-2xl font-extrabold text-slate-400 mt-1 block font-mono">--</span>
                    @endif
                </div>
                <div class="w-10 h-10 bg-rose-50 border border-rose-100 rounded-xl flex-shrink-0 flex items-center justify-center text-rose-600">
                    <i class="fa-solid fa-triangle-exclamation text-base"></i>
                </div>
            </div>
            @if($dbStatus === 'OK')
                <span class="text-[11px] text-slate-500 mt-2 block truncate">Cảnh báo gửi thất bại</span>
            @else
                <span class="text-[11px] text-rose-500 font-bold mt-2 block truncate" title="{{ $dbStatus }}">N/A (CSDL Offline)</span>
            @endif
        </div>

        <!-- Health Card (DB & Redis) -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-center space-x-1.5 mb-2 min-w-0">
                <i class="fa-solid fa-server text-slate-400 text-xs"></i>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block truncate cursor-default" title="Trạng thái kết nối CSDL PostgreSQL & Redis Queue">Kết nối dịch vụ</span>
            </div>
            <div class="space-y-1.5 text-xs">
                <div class="flex items-center justify-between">
                    <span class="text-slate-600 font-medium">CSDL PostgreSQL:</span>
                    @if($dbStatus === 'OK')
                        <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded font-bold font-mono">OK</span>
                    @else
                        <span onclick="alert('Chi tiết lỗi kết nối PostgreSQL:\n' + {{ json_encode($dbStatus) }})" class="px-2 py-0.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded font-bold font-mono cursor-pointer transition-colors flex items-center space-x-1" title="Bấm để xem chi tiết lỗi connection">
                            <span>ERROR</span>
                            <i class="fa-solid fa-circle-info text-[10px]"></i>
                        </span>
                    @endif
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600 font-medium">Hàng chờ Redis:</span>
                    @if($redisStatus === 'OK')
                        <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded font-bold font-mono">OK</span>
                    @else
                        <span onclick="alert('Chi tiết lỗi kết nối Redis:\n' + {{ json_encode($redisStatus) }})" class="px-2 py-0.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded font-bold font-mono cursor-pointer transition-colors flex items-center space-x-1" title="Bấm để xem chi tiết lỗi connection">
                            <span>ERROR</span>
                            <i class="fa-solid fa-circle-info text-[10px]"></i>
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>


    <!-- Spool Folders Grid (Audit Spool & Freshdesk Webhook Spool) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Audit Spool Folder Status -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-slate-900 flex items-center space-x-2 min-w-0">
                    <i class="fa-solid fa-comments text-sky-600 flex-shrink-0"></i>
                    <span class="truncate" title="Tiến trình gửi cảnh báo Rocket.Chat">Gửi cảnh báo Rocket.Chat</span>
                </h2>
                <button onclick="openSpoolModal('rocketchat', 'temporary')" class="bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors flex items-center space-x-1.5 shadow-xs flex-shrink-0">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>Xem hàng chờ</span>
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div onclick="openSpoolModal('rocketchat', 'temporary')" class="flex items-center justify-between p-3 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="1. Khởi tạo tệp đệm tin nhắn tạm">
                    <div class="flex items-center space-x-2 min-w-0">
                        <span class="w-2.5 h-2.5 rounded-full bg-slate-400 flex-shrink-0"></span>
                        <span class="text-xs font-semibold text-slate-700 truncate">1. Tạo tin tạm</span>
                    </div>
                    <span class="text-xs font-bold text-slate-500 font-mono flex-shrink-0 ml-2 bg-white px-2 py-0.5 rounded border border-slate-200">{{ $spoolCounts['temporary'] }}</span>
                </div>

                <div onclick="openSpoolModal('rocketchat', 'pending')" class="flex items-center justify-between p-3 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="2. Đang gửi sang kênh Rocket.Chat">
                    <div class="flex items-center space-x-2 min-w-0">
                        <span class="w-2.5 h-2.5 rounded-full bg-sky-500 flex-shrink-0"></span>
                        <span class="text-xs font-semibold text-slate-700 truncate">2. Đang gửi Rocket</span>
                    </div>
                    <span class="text-xs font-bold text-sky-600 font-mono flex-shrink-0 ml-2 bg-sky-50 px-2 py-0.5 rounded border border-sky-200">{{ $spoolCounts['pending'] }}</span>
                </div>

                <div onclick="openSpoolModal('rocketchat', 'ready')" class="flex items-center justify-between p-3 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="3. Đã gửi xong Rocket, chờ lưu CSDL">
                    <div class="flex items-center space-x-2 min-w-0">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                        <span class="text-xs font-semibold text-slate-700 truncate">3. Chờ lưu CSDL</span>
                    </div>
                    <span class="text-xs font-bold text-emerald-600 font-mono flex-shrink-0 ml-2 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">{{ $spoolCounts['ready'] }}</span>
                </div>

                <div onclick="openSpoolModal('rocketchat', 'processing')" class="flex items-center justify-between p-3 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="4. Đang lưu lịch sử vào CSDL PostgreSQL">
                    <div class="flex items-center space-x-2 min-w-0">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                        <span class="text-xs font-semibold text-slate-700 truncate">4. Đang lưu CSDL</span>
                    </div>
                    <span class="text-xs font-bold text-amber-600 font-mono flex-shrink-0 ml-2 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">{{ $spoolCounts['processing'] }}</span>
                </div>
            </div>
        </div>

        <!-- Freshdesk Webhook Spool Status -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-slate-900 flex items-center space-x-2 min-w-0">
                    <i class="fa-solid fa-life-ring text-indigo-600 flex-shrink-0"></i>
                    <span class="truncate" title="Tiếp nhận sự cố Freshdesk Webhook">Tiếp nhận sự cố Freshdesk</span>
                </h2>
                <button onclick="openSpoolModal('freshdesk', 'ready')" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors flex items-center space-x-1.5 shadow-xs flex-shrink-0">
                    <i class="fa-solid fa-folder-tree"></i>
                    <span>Xem hàng chờ</span>
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                <div onclick="openSpoolModal('freshdesk', 'temporary')" class="flex items-center justify-between p-2.5 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="1. Tiếp nhận sự cố Freshdesk Webhook">
                    <span class="text-xs font-semibold text-slate-700 truncate">1. Tiếp nhận</span>
                    <span class="text-xs font-bold text-slate-500 font-mono ml-1 bg-white px-1.5 py-0.5 rounded border border-slate-200">{{ $freshdeskSpoolCounts['temporary'] }}</span>
                </div>

                <div onclick="openSpoolModal('freshdesk', 'ready')" class="flex items-center justify-between p-2.5 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="2. Chờ nạp vào Redis Queue">
                    <span class="text-xs font-semibold text-slate-700 truncate">2. Chờ Queue</span>
                    <span class="text-xs font-bold text-indigo-600 font-mono ml-1 bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-200">{{ $freshdeskSpoolCounts['ready'] }}</span>
                </div>

                <div onclick="openSpoolModal('freshdesk', 'enqueued')" class="flex items-center justify-between p-2.5 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="3. Đã nạp vào Redis Queue">
                    <span class="text-xs font-semibold text-slate-700 truncate">3. Đã Queue</span>
                    <span class="text-xs font-bold text-sky-600 font-mono ml-1 bg-sky-50 px-1.5 py-0.5 rounded border border-sky-200">{{ $freshdeskSpoolCounts['enqueued'] }}</span>
                </div>

                <div onclick="openSpoolModal('freshdesk', 'processing')" class="flex items-center justify-between p-2.5 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="4. Worker đang xử lý tính toán SLA">
                    <span class="text-xs font-semibold text-slate-700 truncate">4. Đang xử lý</span>
                    <span class="text-xs font-bold text-amber-600 font-mono ml-1 bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200">{{ $freshdeskSpoolCounts['processing'] }}</span>
                </div>

                <div onclick="openSpoolModal('freshdesk', 'committed-gc')" class="flex items-center justify-between p-2.5 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="5. Hoàn thành xử lý và lưu nhật ký">
                    <span class="text-xs font-semibold text-slate-700 truncate">5. Hoàn thành</span>
                    <span class="text-xs font-bold text-emerald-600 font-mono ml-1 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">{{ $freshdeskSpoolCounts['committed-gc'] }}</span>
                </div>

                <div onclick="openSpoolModal('freshdesk', 'quarantine')" class="flex items-center justify-between p-2.5 bg-slate-50/80 rounded-lg border border-slate-200 hover:bg-slate-100/80 cursor-pointer transition-colors" title="6. Tạm dừng do lỗi dữ liệu (Cách ly)">
                    <span class="text-xs font-semibold text-slate-700 truncate">6. Cách ly lỗi</span>
                    <span class="text-xs font-bold text-rose-600 font-mono ml-1 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">{{ $freshdeskSpoolCounts['quarantine'] }}</span>
                </div>
            </div>
        </div>
    </div>


    <!-- System Log Files & Recent Audit Logs Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- System Log Files (1 col) -->
        <div class="bg-white border border-slate-200/80 rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-slate-900 flex items-center space-x-2">
                    <i class="fa-solid fa-bug text-sky-600"></i>
                    <span>Nhật ký lỗi ứng dụng</span>
                </h2>
                <a href="{{ route('admin.system_logs') }}" class="text-xs text-sky-600 hover:text-sky-800 font-semibold">Xem tất cả &rarr;</a>
            </div>
            <div class="space-y-3">
                @forelse($logFiles as $logFile)
                    <div class="flex items-center justify-between p-3 bg-slate-50/80 rounded-lg border border-slate-200">
                        <div class="flex items-center space-x-2.5 min-w-0">
                            <i class="fa-solid fa-file-code text-slate-400"></i>
                            <span class="text-xs font-semibold text-slate-800 font-mono truncate">{{ $logFile['display_name'] }}</span>
                        </div>
                        <div class="text-right flex-shrink-0 ml-2">
                            <span class="text-xs font-bold text-sky-600 font-mono block">{{ $logFile['size'] }}</span>
                            <span class="text-[10px] text-slate-400 block">{{ $logFile['updated_at'] }}</span>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6 text-xs text-slate-400">Chưa có tệp log hệ thống nào.</div>
                @endforelse
            </div>
        </div>

        <!-- Recent RocketChat Delivery Status Table (2 cols) -->
        <div class="lg:col-span-2 bg-white border border-slate-200/80 rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-slate-900 flex items-center space-x-2">
                    <i class="fa-solid fa-list-check text-sky-600"></i>
                    <span>Lịch sử gửi cảnh báo gần đây</span>
                </h2>
                <a href="{{ route('admin.rocketchat_audit') }}" class="text-xs text-sky-600 hover:text-sky-800 font-semibold">Xem đầy đủ &rarr;</a>
            </div>

            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="text-xs text-slate-500 uppercase bg-slate-100/80 border-b border-slate-200 font-bold">
                        <tr>
                            <th class="px-3.5 py-3 whitespace-nowrap">Mã đơn gửi</th>
                            <th class="px-3.5 py-3 whitespace-nowrap">Mã sự cố</th>
                            <th class="px-3.5 py-3 whitespace-nowrap">Trạng thái</th>
                            <th class="px-3.5 py-3 whitespace-nowrap">Mã HTTP</th>
                            <th class="px-3.5 py-3 whitespace-nowrap">Số lần thử</th>
                            <th class="px-3.5 py-3 whitespace-nowrap">Thời gian gửi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100 font-mono text-xs">
                        @forelse($recentAuditLogs as $log)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-3.5 py-3 font-semibold text-slate-900 whitespace-nowrap">{{ Str::limit($log->delivery_id, 16) }}</td>
                                <td class="px-3.5 py-3 text-sky-700 font-bold whitespace-nowrap">{{ $log->event_code }}</td>
                                <td class="px-3.5 py-3 whitespace-nowrap">
                                    @if($log->status === 'SUCCESS')
                                        <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded font-bold">SUCCESS</span>
                                    @elseif($log->status === 'FAILED')
                                        <span class="bg-rose-50 text-rose-700 border border-rose-200 px-2 py-0.5 rounded font-bold">FAILED</span>
                                    @else
                                        <span class="bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded font-bold">{{ $log->status }}</span>
                                    @endif
                                </td>
                                <td class="px-3.5 py-3 whitespace-nowrap font-medium">{{ $log->http_status ?? '--' }}</td>
                                <td class="px-3.5 py-3 whitespace-nowrap">{{ $log->attempt_count }}</td>
                                <td class="px-3.5 py-3 text-slate-500 whitespace-nowrap">{{ $log->formatted_attempted_at }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400 font-sans">
                                    @if($dbStatus !== 'OK')
                                        <div class="max-w-md mx-auto">
                                            <i class="fa-solid fa-triangle-exclamation text-amber-500 text-2xl mb-2"></i>
                                            <h4 class="text-xs font-bold text-slate-700 mb-1">CSDL PostgreSQL hiện đang ngắt kết nối</h4>
                                            <p class="text-[11px] text-slate-400 leading-relaxed">Vui lòng kiểm tra và khởi động lại container <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-600 font-mono">timer-v34-postgres</code> để truy vấn nhật ký CSDL.</p>
                                        </div>
                                    @else
                                        <span>Chưa có nhật ký audit nào trong CSDL.</span>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Unified Spool File Browser Modal -->
<div id="spool-browser-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4 md:p-6">
    <div class="bg-white border border-slate-200 rounded-2xl w-full max-w-7xl h-[90vh] shadow-2xl overflow-hidden flex flex-col">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50 flex-shrink-0">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-600">
                    <i class="fa-solid fa-folder-open text-xl"></i>
                </div>
                <div>
                    <h3 id="modal-spool-title" class="text-xl font-bold text-slate-900 tracking-tight">Duyệt nội dung tệp đệm đĩa</h3>
                    <p id="modal-spool-path" class="text-xs text-slate-500 font-mono">Path: storage/app/rocketchat-audit/</p>
                </div>
            </div>
            <button onclick="closeSpoolModal()" class="w-9 h-9 rounded-xl bg-slate-200/80 hover:bg-slate-300 text-slate-600 hover:text-slate-900 flex items-center justify-center transition-colors shadow-xs">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Folder Tabs Header -->
        <div id="spool-tabs-container" class="flex border-b border-slate-200 bg-slate-100 px-6 gap-2 pt-3 flex-shrink-0 overflow-x-auto">
            <!-- Dynamic tabs will be inserted here -->
        </div>

        <!-- Modal Body: Split Pane -->
        <div class="flex-1 flex min-h-0 divide-x divide-slate-200">
            <!-- Left Pane: File List -->
            <div class="w-1/3 p-4 overflow-y-auto space-y-2 bg-slate-50/60">
                <div id="spool-files-loading" class="text-center py-12 text-slate-500 text-sm flex items-center justify-center space-x-2">
                    <i class="fa-solid fa-circle-notch fa-spin text-sky-600 text-lg"></i>
                    <span class="font-medium">Đang tải danh sách tệp đệm...</span>
                </div>
                <div id="spool-files-empty" class="hidden text-center py-12 px-4">
                    <div class="w-12 h-12 rounded-2xl bg-slate-100 border border-slate-200/80 flex items-center justify-center text-slate-400 mx-auto mb-3 shadow-xs">
                        <i class="fa-solid fa-inbox text-xl"></i>
                    </div>
                    <h4 class="text-xs font-bold text-slate-700 mb-1">Chưa có tệp đệm</h4>
                    <p class="text-[11px] text-slate-400 leading-relaxed max-w-[200px] mx-auto">Thư mục này hiện tại không có tệp tin nào đang chờ xử lý.</p>
                </div>
                <div id="spool-files-list" class="space-y-2 font-mono text-xs"></div>
            </div>

            <!-- Right Pane: JSON Viewer -->
            <div class="w-2/3 p-5 overflow-y-auto flex flex-col bg-slate-50/70">
                <div id="spool-content-header" class="mb-4 hidden items-center justify-between p-3 bg-white border border-slate-200 rounded-xl shadow-xs">
                    <div class="flex items-center space-x-2.5 min-w-0">
                        <i class="fa-solid fa-file-lines text-sky-600 text-base flex-shrink-0"></i>
                        <span id="spool-file-title" class="font-mono text-xs font-bold text-slate-800 truncate"></span>
                    </div>
                    <span id="spool-file-status" class="text-xs px-3 py-1 rounded-full font-bold bg-sky-50 text-sky-700 border border-sky-200 flex-shrink-0"></span>
                </div>
                <div id="spool-content-placeholder" class="flex-1 flex flex-col items-center justify-center text-slate-500 text-sm py-20 px-6">
                    <div class="w-16 h-16 rounded-2xl bg-white border border-slate-200 flex items-center justify-center text-sky-600 shadow-sm mb-4">
                        <i class="fa-solid fa-file-code text-2xl"></i>
                    </div>
                    <h4 class="text-sm font-bold text-slate-800 mb-1">Duyệt nội dung JSON Payload</h4>
                    <p class="text-xs text-slate-500 text-center max-w-sm leading-relaxed">Chọn một tệp từ danh sách bên trái để xem chi tiết thông số kỹ thuật và dữ liệu sự cố.</p>
                </div>
                <pre id="spool-content-json" class="hidden flex-1 p-5 bg-slate-950 text-emerald-300 rounded-xl border border-slate-800 text-xs font-mono overflow-x-auto whitespace-pre-wrap leading-relaxed shadow-lg"></pre>
            </div>
        </div>
    </div>
</div>

<script>
    let currentSpoolType = 'rocketchat';
    let currentSpoolFolder = 'ready';
    let autoRefreshTimer = null;
    let autoRefreshCountdownInterval = null;
    let countdownSeconds = 0;
    const dashboardRefreshCooldownKey = 'admin-dashboard-refresh-unlock-at';
    const dashboardRefreshCooldownMs = 5000;
    let dashboardRefreshCooldownTimer = null;

    function requestDashboardRefresh(event) {
        const unlockAt = Number(sessionStorage.getItem(dashboardRefreshCooldownKey) || 0);

        if (Date.now() < unlockAt) {
            event.preventDefault();
            updateDashboardRefreshButton();
            return false;
        }

        sessionStorage.setItem(
            dashboardRefreshCooldownKey,
            String(Date.now() + dashboardRefreshCooldownMs)
        );

        return true;
    }

    function updateDashboardRefreshButton() {
        const button = document.getElementById('dashboard-refresh-button');
        const label = document.getElementById('dashboard-refresh-label');

        if (!button || !label) return;

        const unlockAt = Number(sessionStorage.getItem(dashboardRefreshCooldownKey) || 0);
        const remainingMs = Math.max(0, unlockAt - Date.now());

        if (remainingMs > 0) {
            button.setAttribute('aria-disabled', 'true');
            button.classList.add('opacity-60');
            label.textContent = `Làm mới sau ${Math.ceil(remainingMs / 1000)}s`;
            dashboardRefreshCooldownTimer = window.setTimeout(updateDashboardRefreshButton, 100);
            return;
        }

        sessionStorage.removeItem(dashboardRefreshCooldownKey);
        button.removeAttribute('aria-disabled');
        button.classList.remove('opacity-60');
        label.textContent = 'Làm mới dữ liệu';

        if (dashboardRefreshCooldownTimer) {
            window.clearTimeout(dashboardRefreshCooldownTimer);
            dashboardRefreshCooldownTimer = null;
        }
    }

    updateDashboardRefreshButton();

    // Auto Refresh Logic
    function changeAutoRefresh(seconds) {
        seconds = parseInt(seconds);
        if (autoRefreshTimer) clearInterval(autoRefreshTimer);
        if (autoRefreshCountdownInterval) clearInterval(autoRefreshCountdownInterval);

        const countdownEl = document.getElementById('auto-refresh-countdown');

        if (seconds > 0) {
            countdownSeconds = seconds;
            countdownEl.classList.remove('hidden');
            countdownEl.innerText = countdownSeconds + 's';

            autoRefreshCountdownInterval = setInterval(() => {
                countdownSeconds--;
                if (countdownSeconds <= 0) {
                    countdownSeconds = seconds;
                    window.location.reload();
                }
                countdownEl.innerText = countdownSeconds + 's';
            }, 1000);
        } else {
            countdownEl.classList.add('hidden');
        }
    }

    // Spool Modal Logic
    const spoolConfig = {
        rocketchat: {
            title: 'Hàng chờ gửi cảnh báo Rocket.Chat',
            path: 'storage/app/rocketchat-audit/',
            tabs: [
                { id: 'temporary', label: '1. Tạo tin tạm', color: 'bg-slate-400', count: {{ $spoolCounts['temporary'] }} },
                { id: 'pending', label: '2. Đang gửi Rocket', color: 'bg-sky-500', count: {{ $spoolCounts['pending'] }} },
                { id: 'ready', label: '3. Chờ lưu CSDL', color: 'bg-emerald-500', count: {{ $spoolCounts['ready'] }} },
                { id: 'processing', label: '4. Đang lưu CSDL', color: 'bg-amber-500', count: {{ $spoolCounts['processing'] }} }
            ]
        },
        freshdesk: {
            title: 'Hàng chờ tiếp nhận sự cố Freshdesk',
            path: 'storage/app/freshdesk-spool/',
            tabs: [
                { id: 'temporary', label: '1. Tiếp nhận', color: 'bg-slate-400', count: {{ $freshdeskSpoolCounts['temporary'] }} },
                { id: 'ready', label: '2. Chờ nạp Queue', color: 'bg-indigo-500', count: {{ $freshdeskSpoolCounts['ready'] }} },
                { id: 'enqueued', label: '3. Đã nạp Queue', color: 'bg-sky-500', count: {{ $freshdeskSpoolCounts['enqueued'] }} },
                { id: 'processing', label: '4. Đang xử lý', color: 'bg-amber-500', count: {{ $freshdeskSpoolCounts['processing'] }} },
                { id: 'committed-gc', label: '5. Hoàn thành', color: 'bg-emerald-500', count: {{ $freshdeskSpoolCounts['committed-gc'] }} },
                { id: 'quarantine', label: '6. Cách ly lỗi', color: 'bg-rose-500', count: {{ $freshdeskSpoolCounts['quarantine'] }} }
            ]
        }
    };

    function openSpoolModal(type = 'rocketchat', folder = 'ready') {
        currentSpoolType = type;
        currentSpoolFolder = folder;

        document.getElementById('modal-spool-title').innerText = spoolConfig[type].title;
        document.getElementById('modal-spool-path').innerText = 'Path: ' + spoolConfig[type].path;

        renderSpoolTabs();

        document.getElementById('spool-browser-modal').classList.remove('hidden');
        document.getElementById('spool-browser-modal').classList.add('flex');
        switchSpoolFolder(folder);
    }

    function renderSpoolTabs() {
        const container = document.getElementById('spool-tabs-container');
        container.innerHTML = '';

        spoolConfig[currentSpoolType].tabs.forEach(tab => {
            const btn = document.createElement('button');
            btn.id = 'spool-tab-' + tab.id;
            btn.onclick = () => switchSpoolFolder(tab.id);
            btn.className = 'px-3.5 py-2 text-xs font-bold rounded-t-xl transition-all border-b-2 border-transparent text-slate-500 hover:text-slate-900 flex items-center space-x-2 flex-shrink-0';
            btn.innerHTML = `
                <span class="w-2 h-2 rounded-full ${tab.color}"></span>
                <span>${tab.label}</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] bg-slate-200 text-slate-700 font-mono font-bold">(${tab.count})</span>
            `;
            container.appendChild(btn);
        });
    }

    function closeSpoolModal() {
        document.getElementById('spool-browser-modal').classList.add('hidden');
        document.getElementById('spool-browser-modal').classList.remove('flex');
    }

    function switchSpoolFolder(folder) {
        currentSpoolFolder = folder;
        spoolConfig[currentSpoolType].tabs.forEach(tab => {
            const tabBtn = document.getElementById('spool-tab-' + tab.id);
            if (!tabBtn) return;
            if (tab.id === folder) {
                tabBtn.className = 'px-3.5 py-2 text-xs font-bold rounded-t-xl transition-all border-b-2 border-sky-600 text-sky-600 bg-white shadow-xs flex items-center space-x-2 flex-shrink-0';
            } else {
                tabBtn.className = 'px-3.5 py-2 text-xs font-bold rounded-t-xl transition-all border-b-2 border-transparent text-slate-500 hover:text-slate-900 flex items-center space-x-2 flex-shrink-0';
            }
        });

        fetchSpoolFiles(currentSpoolType, folder);
    }

    function fetchSpoolFiles(type, folder) {
        const loading = document.getElementById('spool-files-loading');
        const empty = document.getElementById('spool-files-empty');
        const list = document.getElementById('spool-files-list');
        const jsonView = document.getElementById('spool-content-json');
        const placeholder = document.getElementById('spool-content-placeholder');
        const contentHeader = document.getElementById('spool-content-header');

        loading.classList.remove('hidden');
        empty.classList.add('hidden');
        list.innerHTML = '';
        jsonView.classList.add('hidden');
        contentHeader.classList.add('hidden');
        contentHeader.classList.remove('flex');
        placeholder.classList.remove('hidden');
        placeholder.innerHTML = '<div class="w-16 h-16 rounded-2xl bg-white border border-slate-200 flex items-center justify-center text-sky-600 shadow-sm mb-4"><i class="fa-solid fa-file-code text-2xl"></i></div><h4 class="text-sm font-bold text-slate-800 mb-1">Duyệt nội dung JSON Payload</h4><p class="text-xs text-slate-500 text-center max-w-sm leading-relaxed">Chọn một tệp từ danh sách bên trái để xem chi tiết thông số kỹ thuật và dữ liệu sự cố.</p>';

        fetch(`{{ route('admin.spool_files') }}?type=${type}&folder=${folder}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(res => {
                if (!res.ok) {
                    if (res.status === 401 || res.status === 419) {
                        window.location.reload();
                        return;
                    }
                    throw new Error(`Tải dữ liệu thất bại (${res.status})`);
                }
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    window.location.reload();
                    return;
                }
                return res.json();
            })
            .then(data => {
                if (!data) return;
                loading.classList.add('hidden');
                if (!data.files || data.files.length === 0) {
                    empty.innerHTML = `
                        <div class="w-12 h-12 rounded-2xl bg-slate-100 border border-slate-200/80 flex items-center justify-center text-slate-400 mx-auto mb-3 shadow-xs">
                            <i class="fa-solid fa-inbox text-xl"></i>
                        </div>
                        <h4 class="text-xs font-bold text-slate-700 mb-1">Chưa có tệp đệm</h4>
                        <p class="text-[11px] text-slate-400 leading-relaxed max-w-[200px] mx-auto">Thư mục này hiện tại không có tệp tin nào đang chờ xử lý.</p>
                    `;
                    empty.classList.remove('hidden');
                    return;
                }

                data.files.forEach(file => {
                    const item = document.createElement('div');
                    item.className = 'p-2.5 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 hover:border-sky-400 cursor-pointer transition-all flex items-center justify-between group shadow-xs';
                    item.onclick = () => viewSpoolFile(type, file.path, item);
                    item.innerHTML = `
                        <div class="flex items-center space-x-2 min-w-0">
                            <i class="fa-solid fa-file-code text-slate-400 group-hover:text-sky-600 transition-colors"></i>
                            <span class="truncate text-slate-700 group-hover:text-slate-900 font-semibold text-xs">${file.name}</span>
                        </div>
                        <div class="text-right flex-shrink-0 ml-2">
                            <span class="text-[10px] text-sky-600 block font-bold font-mono">${file.size}</span>
                            <span class="text-[9px] text-slate-400 block">${file.updated_at}</span>
                        </div>
                    `;
                    list.appendChild(item);
                });
            })
            .catch(err => {
                loading.classList.add('hidden');
                empty.innerHTML = `
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-500 mx-auto mb-3 shadow-xs">
                        <i class="fa-solid fa-circle-exclamation text-xl"></i>
                    </div>
                    <h4 class="text-xs font-bold text-slate-700 mb-1">Không thể tải dữ liệu</h4>
                    <p class="text-[11px] text-slate-400 leading-relaxed max-w-[200px] mx-auto mb-2">Phiên đăng nhập có thể đã hết hạn hoặc kết nối bị gián đoạn.</p>
                    <button onclick="window.location.reload()" class="bg-sky-600 hover:bg-sky-700 text-white font-semibold text-[11px] px-3 py-1 rounded-lg transition-colors shadow-xs">
                        <i class="fa-solid fa-rotate mr-1"></i> Tải lại trang
                    </button>
                `;
                empty.classList.remove('hidden');
            });
    }

    function viewSpoolFile(type, path, element) {
        document.querySelectorAll('#spool-files-list > div').forEach(el => {
            el.classList.remove('bg-sky-50', 'border-sky-500');
        });
        if (element) {
            element.classList.add('bg-sky-50', 'border-sky-500');
        }

        const jsonView = document.getElementById('spool-content-json');
        const placeholder = document.getElementById('spool-content-placeholder');
        const contentHeader = document.getElementById('spool-content-header');
        const fileTitle = document.getElementById('spool-file-title');
        const fileStatus = document.getElementById('spool-file-status');

        jsonView.classList.add('hidden');
        placeholder.classList.remove('hidden');
        placeholder.innerHTML = '<div class="flex items-center space-x-2 text-sky-600 font-semibold text-xs"><i class="fa-solid fa-circle-notch fa-spin text-lg"></i><span>Đang đọc nội dung tệp đệm...</span></div>';

        fetch(`{{ route('admin.spool_files.view') }}?type=${type}&path=${encodeURIComponent(path)}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    placeholder.innerHTML = `<span class="text-rose-400 font-bold">${data.error}</span>`;
                    return;
                }

                placeholder.classList.add('hidden');
                contentHeader.classList.remove('hidden');
                contentHeader.classList.add('flex');
                fileTitle.innerText = data.filename;

                if (data.parsed && data.parsed.event_code) {
                    fileStatus.innerText = 'Sự cố: ' + data.parsed.event_code;
                } else {
                    fileStatus.innerText = 'JSON PAYLOAD';
                }

                let formatted = data.raw_content;
                if (data.parsed) {
                    formatted = JSON.stringify(data.parsed, null, 2);
                }

                jsonView.innerText = formatted;
                jsonView.classList.remove('hidden');
            })
            .catch(err => {
                placeholder.innerHTML = `<span class="text-rose-400 font-bold">Lỗi đọc file: ${err}</span>`;
            });
    }
</script>
@endsection
