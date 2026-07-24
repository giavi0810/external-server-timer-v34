<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BasicAuthMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('freshdesk.basic_auth.username');
        $password = config('freshdesk.basic_auth.password');

        if ($request->getUser() !== $username || $request->getPassword() !== $password) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401, ['WWW-Authenticate' => 'Basic']);
        }

        return $next($request);
    }
}
