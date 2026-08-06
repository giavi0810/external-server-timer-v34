<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function showLoginForm()
    {
        if (session('admin_logged_in', false)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $expectedUser = config('services.admin.username', env('ADMIN_USERNAME', 'admin'));
        $expectedPass = config('services.admin.password', env('ADMIN_PASSWORD'));

        if ($request->input('username') === $expectedUser && $request->input('password') === $expectedPass) {
            session([
                'admin_logged_in' => true,
                'admin_user' => $expectedUser,
            ]);

            return redirect()->route('admin.dashboard')->with('success', 'Đăng nhập thành công!');
        }

        return back()->withInput($request->only('username'))->with('error', 'Tên đăng nhập hoặc mật khẩu không chính xác.');
    }

    public function logout()
    {
        session()->forget(['admin_logged_in', 'admin_user']);
        return redirect()->route('admin.login')->with('info', 'Đã đăng xuất khỏi hệ thống.');
    }
}
