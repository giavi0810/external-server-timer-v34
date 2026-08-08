<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = null;

        if ($request->session()->get('admin_logged_in', false)) {
            $admin = AdminUser::query()->find($request->session()->get('admin_user_id'));
        }

        if (! $admin || ! $admin->is_active) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            return redirect()->route('admin.login')->with('error', 'Vui lòng đăng nhập để truy cập trang quản trị.');
        }

        $request->session()->put([
            'admin_user' => $admin->username,
            'admin_username' => $admin->username,
            'admin_role' => $admin->role,
        ]);
        $request->attributes->set('admin_user', $admin);

        return $next($request);
    }
}
