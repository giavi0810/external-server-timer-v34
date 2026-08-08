@php($fieldUser = $editingUser)
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Họ tên</label>
        <input name="name" required maxlength="150" value="{{ $fieldUser?->name }}" placeholder="Nhập họ và tên" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
    </div>
    <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Username</label>
        <input name="username" required maxlength="100" value="{{ $fieldUser?->username }}" placeholder="Tên đăng nhập" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-mono text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
    </div>

    <div class="sm:col-span-2">
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
            Email <span class="font-normal text-slate-400 lowercase">(không bắt buộc)</span>
        </label>
        <input type="email" name="email" maxlength="255" value="{{ $fieldUser?->email }}" placeholder="address@example.com" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
    </div>

    <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Role</label>
        <select name="role" required class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
            @foreach($roles as $role)
                <option value="{{ $role }}" @selected(($fieldUser?->role ?? 'viewer') === $role)>{{ $role }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex items-end pb-0.5">
        <label class="inline-flex items-center gap-2.5 cursor-pointer rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors w-full">
            <input type="checkbox" name="is_active" value="1" @checked($fieldUser?->is_active ?? true) class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
            <span>Đang hoạt động</span>
        </label>
    </div>

    <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
            {{ $fieldUser ? 'Mật khẩu mới' : 'Mật khẩu' }}
            @if($fieldUser)<span class="font-normal text-slate-400 lowercase">(bỏ trống nếu giữ nguyên)</span>@endif
        </label>
        <input type="password" name="password" {{ $fieldUser ? '' : 'required' }} autocomplete="new-password" placeholder="••••••••••••" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
    </div>

    <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Xác nhận mật khẩu</label>
        <input type="password" name="password_confirmation" {{ $fieldUser ? '' : 'required' }} autocomplete="new-password" placeholder="••••••••••••" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-slate-50/80 p-3 flex items-center gap-2.5 text-xs text-slate-600">
    <i class="fa-solid fa-shield-halved text-slate-400 text-sm shrink-0"></i>
    <span>Mật khẩu tối thiểu 12 ký tự, bao gồm chữ hoa, chữ thường và chữ số.</span>
</div>
