<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->append(\App\Http\Middleware\TraceIdMiddleware::class);

        $middleware->alias([
            'auth.basic.fd' => \App\Http\Middleware\BasicAuthMiddleware::class,
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

        $exceptions->reportable(function (\Throwable $e): void {
            if (class_exists(\App\Services\RocketChatService::class)) {
                app(\App\Services\RocketChatService::class)->sendSystemErrorAlert($e);
            }
        });
    })->create();
