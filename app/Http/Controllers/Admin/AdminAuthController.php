<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    public function __construct(private readonly AdminAuditService $auditService)
    {
    }

    public function showLoginForm()
    {
        if (session('admin_logged_in', false)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username = trim((string) $credentials['username']);
        $throttleKey = 'admin-login|'.Str::lower($username).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()
                ->withInput($request->only('username'))
                ->with('error', 'Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau '.RateLimiter::availableIn($throttleKey).' giây.');
        }

        $admin = AdminUser::query()->where('username', $username)->first();

        if (! $admin || ! $admin->is_active || ! Hash::check((string) $credentials['password'], $admin->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withInput($request->only('username'))
                ->with('error', 'Tên đăng nhập hoặc mật khẩu không chính xác.');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put([
            'admin_logged_in' => true,
            'admin_user_id' => $admin->id,
            'admin_user' => $admin->username,
            'admin_username' => $admin->username,
            'admin_role' => $admin->role,
        ]);

        $admin->forceFill(['last_login_at' => now()])->save();
        $request->attributes->set('admin_user', $admin);
        $this->auditService->record($request, 'auth.login', 'admin_user', $admin->id, actor: $admin);

        return redirect()->route('admin.dashboard')->with('success', 'Đăng nhập thành công!');
    }

    public function logout(Request $request)
    {
        $admin = AdminUser::query()->find($request->session()->get('admin_user_id'));

        if ($admin) {
            $this->auditService->record($request, 'auth.logout', 'admin_user', $admin->id, actor: $admin);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('info', 'Đã đăng xuất khỏi hệ thống.');
    }
}
