<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function __construct(private readonly AdminAuditService $auditService)
    {
    }

    public function index(): View
    {
        return view('admin.users.index', [
            'users' => AdminUser::query()->orderBy('username')->paginate(20),
            'roles' => AdminUser::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:admin_users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:admin_users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'role' => ['required', Rule::in(AdminUser::ROLES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($request, $validated): AdminUser {
            $user = AdminUser::query()->create([
                ...$validated,
                'is_active' => $request->boolean('is_active'),
            ]);

            $this->auditService->record(
                $request,
                'admin_user.created',
                'admin_user',
                $user->id,
                newValues: $user->only(['name', 'username', 'email', 'role', 'is_active']),
            );

            return $user;
        });

        return redirect()->route('admin.users.index')->with('success', "Đã tạo tài khoản {$user->username}.");
    }

    public function update(Request $request, AdminUser $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('admin_users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'role' => ['required', Rule::in(AdminUser::ROLES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $actor = $request->attributes->get('admin_user');
        $isActive = $request->boolean('is_active');

        if ($actor->is($user) && (! $isActive || $validated['role'] !== AdminUser::ROLE_SUPER_ADMIN)) {
            return back()->with('error', 'Bạn không thể tự khóa tài khoản hoặc tự gỡ quyền super_admin.');
        }

        DB::transaction(function () use ($request, $user, $validated, $isActive): void {
            $lockedUser = AdminUser::query()->lockForUpdate()->findOrFail($user->id);

            $activeSuperAdminIds = AdminUser::query()
                ->where('role', AdminUser::ROLE_SUPER_ADMIN)
                ->where('is_active', true)
                ->lockForUpdate()
                ->pluck('id');

            if ($lockedUser->role === AdminUser::ROLE_SUPER_ADMIN
                && ($validated['role'] !== AdminUser::ROLE_SUPER_ADMIN || ! $isActive)
                && $activeSuperAdminIds->reject(fn (int $id): bool => $id === $lockedUser->id)->isEmpty()) {
                throw ValidationException::withMessages([
                    'role' => 'Hệ thống phải luôn còn ít nhất một super_admin đang hoạt động.',
                ]);
            }

            $oldValues = $lockedUser->only(['name', 'username', 'email', 'role', 'is_active']);
            $changes = [
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'role' => $validated['role'],
                'is_active' => $isActive,
            ];

            if (! empty($validated['password'])) {
                $changes['password'] = $validated['password'];
            }

            $lockedUser->update($changes);
            $newValues = $lockedUser->only(['name', 'username', 'email', 'role', 'is_active']);
            $newValues['password_changed'] = ! empty($validated['password']);

            $this->auditService->record(
                $request,
                'admin_user.updated',
                'admin_user',
                $lockedUser->id,
                $oldValues,
                $newValues,
            );
        });

        return redirect()->route('admin.users.index')->with('success', 'Đã cập nhật tài khoản.');
    }

    public function destroy(Request $request, AdminUser $user): RedirectResponse
    {
        $actor = $request->attributes->get('admin_user');

        if ($actor->is($user)) {
            return back()->with('error', 'Bạn không thể xóa tài khoản đang đăng nhập.');
        }

        DB::transaction(function () use ($request, $user): void {
            $lockedUser = AdminUser::query()->lockForUpdate()->findOrFail($user->id);

            $activeSuperAdminIds = AdminUser::query()
                ->where('role', AdminUser::ROLE_SUPER_ADMIN)
                ->where('is_active', true)
                ->lockForUpdate()
                ->pluck('id');

            if ($lockedUser->role === AdminUser::ROLE_SUPER_ADMIN
                && $activeSuperAdminIds->reject(fn (int $id): bool => $id === $lockedUser->id)->isEmpty()) {
                throw ValidationException::withMessages([
                    'user' => 'Không thể xóa super_admin đang hoạt động cuối cùng.',
                ]);
            }

            $oldValues = $lockedUser->only(['name', 'username', 'email', 'role', 'is_active']);
            $this->auditService->record($request, 'admin_user.deleted', 'admin_user', $lockedUser->id, $oldValues);
            $lockedUser->delete();
        });

        return redirect()->route('admin.users.index')->with('success', 'Đã xóa tài khoản.');
    }
}
