<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceIdMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Trace-ID')
            ?: $request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        Log::withContext([
            'trace_id' => $traceId,
        ]);

        $response = $next($request);

        $response->headers->set('X-Trace-ID', $traceId);

        return $response;
    }
}
