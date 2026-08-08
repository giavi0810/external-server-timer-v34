<!DOCTYPE html>
<html lang="vi" class="h-full bg-slate-50 text-slate-800">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Log & Audit Monitor') — External Server Timer</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            500: '#0284c7',
                            600: '#0369a1',
                            700: '#075985',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="h-full bg-slate-50 font-sans antialiased text-slate-800">
    <div class="min-h-screen flex flex-col">
        <!-- Top Navbar + Sub-Navigation Tabs Bar -->
        <header class="bg-white border-b border-slate-200 sticky top-0 z-50 shadow-xs">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Top Brand Bar -->
                <div class="flex items-center justify-between h-16 border-b border-slate-100">
                    <div class="flex items-center space-x-3 min-w-0">
                        <div class="w-9 h-9 rounded-xl bg-sky-600 flex-shrink-0 flex items-center justify-center text-white shadow-md shadow-sky-600/20">
                            <i class="fa-solid fa-layer-group text-lg"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="font-bold text-base md:text-lg text-slate-900 tracking-wide block whitespace-nowrap leading-tight">HỆ THỐNG GIÁM SÁT LỖI & CẢNH BÁO</span>
                            <span class="text-xs text-sky-600 block font-mono font-medium leading-tight mt-0.5">Bộ đếm thời gian V34</span>
                        </div>
                    </div>

                    <!-- Right Header Tools -->
                    <div class="flex items-center space-x-4 flex-shrink-0">
                        <div class="flex items-center space-x-2 text-sm text-slate-700 bg-slate-100 px-3.5 py-1.5 rounded-full border border-slate-200 shadow-xs">
                            <i class="fa-solid fa-user-shield text-sky-600 text-base"></i>
                            <span>Admin: <strong class="text-slate-900">{{ session('admin_user', 'admin') }}</strong></span>
                        </div>
                        <form action="{{ route('admin.logout') }}" method="POST">
                            @csrf
                            <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors flex items-center space-x-1.5 shadow-sm">
                                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                <span>Đăng xuất</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Sub-Navigation Horizontal Tabs Bar -->
                <nav class="flex items-center space-x-2 pt-2 overflow-x-auto no-scrollbar">
                    <a href="{{ route('admin.dashboard') }}" 
                       class="flex items-center space-x-2 px-4 py-2.5 rounded-t-lg text-xs md:text-sm font-semibold transition-all border-b-2 whitespace-nowrap {{ request()->routeIs('admin.dashboard') ? 'border-sky-600 text-sky-600 bg-sky-50/50' : 'border-transparent text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                        <i class="fa-solid fa-gauge-high text-sky-600"></i>
                        <span>Trang tổng quan</span>
                    </a>

                    <a href="{{ route('admin.rocketchat_audit') }}" 
                       class="flex items-center space-x-2 px-4 py-2.5 rounded-t-lg text-xs md:text-sm font-semibold transition-all border-b-2 whitespace-nowrap {{ request()->routeIs('admin.rocketchat_audit') ? 'border-sky-600 text-sky-600 bg-sky-50/50' : 'border-transparent text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                        <i class="fa-solid fa-bullhorn text-sky-600"></i>
                        <span>Nhật ký gửi cảnh báo</span>
                    </a>

                    <a href="{{ route('admin.system_logs') }}" 
                       class="flex items-center space-x-2 px-4 py-2.5 rounded-t-lg text-xs md:text-sm font-semibold transition-all border-b-2 whitespace-nowrap {{ request()->routeIs('admin.system_logs') ? 'border-sky-600 text-sky-600 bg-sky-50/50' : 'border-transparent text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                        <i class="fa-solid fa-terminal text-sky-600"></i>
                        <span>Nhật ký lỗi hệ thống</span>
                    </a>
                    <a href="{{ route('admin.sla-policies.index') }}"
                       class="flex items-center space-x-2 px-4 py-2.5 rounded-t-lg text-xs md:text-sm font-semibold transition-all border-b-2 whitespace-nowrap {{ request()->routeIs('admin.sla-policies.*') ? 'border-sky-600 text-sky-600 bg-sky-50/50' : 'border-transparent text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                        <i class="fa-solid fa-stopwatch text-sky-600"></i>
                        <span>Quản lý SLA</span>
                    </a>

                    @if(session('admin_role') === 'super_admin')
                        <a href="{{ route('admin.users.index') }}"
                           class="flex items-center space-x-2 px-4 py-2.5 rounded-t-lg text-xs md:text-sm font-semibold transition-all border-b-2 whitespace-nowrap {{ request()->routeIs('admin.users.*') ? 'border-sky-600 text-sky-600 bg-sky-50/50' : 'border-transparent text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                            <i class="fa-solid fa-users-gear text-sky-600"></i>
                            <span>Tài khoản</span>
                        </a>
                    @endif
                </nav>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- Toast Floating Notifications (Top-Right) -->
            @if(session('success'))
                <div id="flash-alert-success"
                    class="fixed top-5 right-5 z-50 bg-emerald-50 border border-emerald-200 text-emerald-900 px-5 py-3.5 rounded-xl shadow-xl flex items-center space-x-3 transition-all duration-500 transform translate-y-0 opacity-100">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-lg"></i>
                    <span class="font-semibold text-sm">{{ session('success') }}</span>
                    <button onclick="dismissAlert('flash-alert-success')"
                        class="text-emerald-500 hover:text-emerald-800 ml-2">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            @endif

            @if(session('error'))
                <div id="flash-alert-error"
                    class="fixed top-5 right-5 z-50 bg-rose-50 border border-rose-200 text-rose-900 px-5 py-3.5 rounded-xl shadow-xl flex items-center space-x-3 transition-all duration-500 transform translate-y-0 opacity-100">
                    <i class="fa-solid fa-circle-exclamation text-rose-600 text-lg"></i>
                    <span class="font-semibold text-sm">{{ session('error') }}</span>
                    <button onclick="dismissAlert('flash-alert-error')" class="text-rose-500 hover:text-rose-800 ml-2">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                    <div class="font-bold mb-2"><i class="fa-solid fa-circle-exclamation mr-1"></i> Dữ liệu chưa hợp lệ</div>
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <script>
                function dismissAlert(id) {
                    var alertEl = document.getElementById(id);
                    if (alertEl) {
                        alertEl.classList.add('opacity-0', '-translate-y-4');
                        setTimeout(function () { alertEl.remove(); }, 500);
                    }
                }

                document.addEventListener('DOMContentLoaded', function () {
                    if (document.getElementById('flash-alert-success')) {
                        setTimeout(function () { dismissAlert('flash-alert-success'); }, 3000);
                    }
                    if (document.getElementById('flash-alert-error')) {
                        setTimeout(function () { dismissAlert('flash-alert-error'); }, 4000);
                    }
                });
            </script>

            @yield('content')
        </main>
    </div>
</body>

</html>
