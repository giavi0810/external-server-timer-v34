<!DOCTYPE html>
<html lang="vi" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập Admin Monitor — External Server Timer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="h-full bg-slate-50 flex items-center justify-center p-4 font-sans text-slate-800">
    <div class="w-full max-w-md bg-white border border-slate-200 rounded-2xl shadow-xl p-8 relative overflow-hidden">
        <!-- Background Decor Dots -->
        <div class="absolute -top-20 -left-20 w-40 h-40 bg-sky-100 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -right-20 w-40 h-40 bg-blue-100 rounded-full blur-2xl pointer-events-none"></div>

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-sky-600 rounded-2xl flex items-center justify-center text-white mx-auto shadow-md shadow-sky-600/20 mb-3">
                <i class="fa-solid fa-shield-halved text-2xl"></i>
            </div>
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">HỆ THỐNG GIÁM SÁT LỖI & CẢNH BÁO</h1>
            <p class="text-xs text-slate-500 mt-1">Đăng nhập quản trị viên để theo dõi nhật ký sự cố và cảnh báo</p>
        </div>

        @if(session('error'))
            <div class="mb-5 bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl text-sm flex items-center space-x-2 font-medium">
                <i class="fa-solid fa-circle-exclamation text-rose-600"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if(session('info'))
            <div class="mb-5 bg-sky-50 border border-sky-200 text-sky-800 px-4 py-3 rounded-xl text-sm flex items-center space-x-2 font-medium">
                <i class="fa-solid fa-circle-info text-sky-600"></i>
                <span>{{ session('info') }}</span>
            </div>
        @endif

        <!-- Form -->
        <form action="{{ route('admin.login.submit') }}" method="POST" class="space-y-5">
            @csrf
            <div>
                <label for="username" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Tên đăng nhập</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-user"></i>
                    </span>
                    <input type="text" id="username" name="username" value="{{ old('username') }}" required
                           class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 text-sm font-medium transition-all"
                           placeholder="Nhập tên đăng nhập...">
                </div>
            </div>

            <div>
                <label for="password" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Mật khẩu</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-key"></i>
                    </span>
                    <input type="password" id="password" name="password" required
                           class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 text-sm font-medium transition-all"
                           placeholder="Nhập mật khẩu...">
                </div>
            </div>

            <button type="submit" 
                    class="w-full py-3 bg-sky-600 hover:bg-sky-700 text-white font-semibold rounded-xl shadow-md shadow-sky-600/20 transition-all text-sm flex items-center justify-center space-x-2">
                <span>Đăng Nhập</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>

        <div class="mt-8 text-center text-xs text-slate-400 font-mono">
            External Server Timer V34 &copy; {{ date('Y') }}
        </div>
    </div>
</body>
</html>

