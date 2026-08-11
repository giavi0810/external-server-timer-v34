<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\Admin\DashboardDatabaseConnection;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminAuthMiddleware
{
    public function __construct(
        private readonly DashboardDatabaseConnection $dashboardDatabase,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = null;

        if ($request->session()->get('admin_logged_in', false)) {
            try {
                if ($request->isMethod('GET') && $request->routeIs('admin.dashboard')) {
                    $this->dashboardDatabase->prepare();
                }

                $admin = AdminUser::query()->find($request->session()->get('admin_user_id'));
            } catch (Throwable $e) {
                if ($this->isDatabaseConnectionError($e) && $this->canAccessDegradedDashboard($request)) {
                    $request->attributes->set('admin_auth_degraded', true);
                    $request->attributes->set('admin_database_error', $e->getMessage());

                    return $next($request);
                }

                if (! $this->isDatabaseConnectionError($e)) {
                    throw $e;
                }

                abort(503, 'Không thể xác minh tài khoản quản trị vì cơ sở dữ liệu đang mất kết nối.');
            }
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

    private function canAccessDegradedDashboard(Request $request): bool
    {
        $adminId = filter_var(
            $request->session()->get('admin_user_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $username = trim((string) $request->session()->get('admin_username', ''));
        $role = $request->session()->get('admin_role');

        return $request->isMethod('GET')
            && $request->routeIs('admin.dashboard')
            && $adminId !== false
            && $username !== ''
            && in_array($role, AdminUser::ROLES, true);
    }

    private function isDatabaseConnectionError(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }
}
