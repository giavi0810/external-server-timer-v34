<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->append(\App\Http\Middleware\TraceIdMiddleware::class);

        $middleware->alias([
            'auth.basic.fd' => \App\Http\Middleware\BasicAuthMiddleware::class,
            'admin.auth' => \App\Http\Middleware\AdminAuthMiddleware::class,
            'admin.role' => \App\Http\Middleware\AdminRoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $exception): bool => $request->is('api/*')
                || $request->expectsJson()
        );

        $exceptions->context(function (): array {
            if (!app()->bound('request')) {
                return [];
            }

            $request = request();
            $traceId = $request->header('X-Trace-ID')
                ?: $request->header('X-Correlation-ID')
                ?: $request->header('X-Request-ID');

            return array_filter([
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'trace_id' => $traceId,
                'correlation_id' => $traceId,
            ]);
        });

        $exceptions->reportable(function (\Throwable $e): ?bool {
            if (str_contains($e->getMessage(), 'failed_jobs_uuid_unique')) {
                Log::info('Duplicate failed_jobs record suppressed from RocketChat alerts (already recorded by parallel worker).');
                return false;
            }

            if (class_exists(\App\Services\RocketChatService::class)) {
                app(\App\Services\RocketChatService::class)->sendSystemErrorAlert($e);
            }

            return null;
        });
    })->create();
