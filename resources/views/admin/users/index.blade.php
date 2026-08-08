@extends('admin.layout')

@section('title', 'Quản lý tài khoản')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Quản lý tài khoản</h1>
            </div>
            <button type="button" onclick="document.getElementById('create-user-dialog').showModal()"
                class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-700">
                <i class="fa-solid fa-user-plus mr-1"></i> Thêm tài khoản
            </button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Tài khoản</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Trạng thái</th>
                        <th class="px-4 py-3">Đăng nhập gần nhất</th>
                        <th class="px-4 py-3 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($users as $user)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-900">{{ $user->name }}</div>
                                <div class="font-mono text-xs text-slate-500">{{ $user->username }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $user->email ?: '—' }}</td>
                            <td class="px-4 py-3"><span
                                    class="rounded-full bg-sky-50 px-2.5 py-1 font-mono text-xs font-semibold text-sky-700">{{ $user->role }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $user->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $user->is_active ? 'Hoạt động' : 'Đã khóa' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $user->last_login_at?->format('d/m/Y H:i:s') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button type="button" onclick="document.getElementById('edit-user-{{ $user->id }}').showModal()"
                                    class="text-sky-700 hover:text-sky-900 font-semibold">Sửa</button>
                                @if(session('admin_user_id') !== $user->id)
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="inline ml-3"
                                        onsubmit="return confirm('Xóa tài khoản {{ $user->username }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="font-semibold text-rose-600 hover:text-rose-800">Xóa</button>
                                    </form>
                                @endif
                            </td>
                        </tr>

                        <dialog id="edit-user-{{ $user->id }}" class="w-full max-w-xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-xs border-0 m-auto">
                            <form action="{{ route('admin.users.update', $user) }}" method="POST" class="flex flex-col">
                                @csrf
                                @method('PUT')
                                <!-- Modal Header -->
                                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                                            <i class="fa-solid fa-user-pen text-base"></i>
                                        </div>
                                        <div>
                                            <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                                                <span>Cập nhật tài khoản</span>
                                                <span class="rounded-full bg-sky-50 px-2.5 py-0.5 font-mono text-xs font-semibold text-sky-700">
                                                    {{ $user->username }}
                                                </span>
                                            </h2>
                                            <p class="text-xs text-slate-500 mt-0.5">Chỉnh sửa thông tin và phân quyền tài khoản</p>
                                        </div>
                                    </div>
                                    <button type="button" onclick="this.closest('dialog').close()" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                                        <i class="fa-solid fa-xmark text-lg"></i>
                                    </button>
                                </div>

                                <!-- Modal Body -->
                                <div class="p-6 space-y-5">
                                    @include('admin.users.partials.form', ['editingUser' => $user, 'roles' => $roles])
                                </div>

                                <!-- Modal Footer -->
                                <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4 rounded-b-2xl">
                                    <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition-colors">
                                        Hủy
                                    </button>
                                    <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-700 transition-colors flex items-center gap-2">
                                        <i class="fa-solid fa-check text-xs"></i>
                                        <span>Lưu thay đổi</span>
                                    </button>
                                </div>
                            </form>
                        </dialog>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $users->links() }}
    </div>

    <dialog id="create-user-dialog" class="w-full max-w-xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-xs border-0 m-auto">
        <form action="{{ route('admin.users.store') }}" method="POST" class="flex flex-col">
            @csrf
            <!-- Modal Header -->
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <div class="flex items-center space-x-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                        <i class="fa-solid fa-user-plus text-base"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Thêm tài khoản mới</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Tạo tài khoản người dùng và gán quyền hệ thống</p>
                    </div>
                </div>
                <button type="button" onclick="this.closest('dialog').close()" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-6 space-y-5">
                @include('admin.users.partials.form', ['editingUser' => null, 'roles' => $roles])
            </div>

            <!-- Modal Footer -->
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4 rounded-b-2xl">
                <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition-colors">
                    Hủy
                </button>
                <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-700 transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span>Tạo tài khoản</span>
                </button>
            </div>
        </form>
    </dialog>
@endsection