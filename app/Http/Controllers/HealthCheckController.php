<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheckController extends Controller
{
    /**
     * Check Redis and Queue status.
     * GET /api/health
     */
    public function check(): JsonResponse
    {
        $status = 'healthy';
        $details = [
            'redis' => ['status' => 'unknown'],
            'freshdesk_spool' => ['status' => 'unknown'],
        ];
        $redisHealthy = false;

        // 1. Check Redis connection
        try {
            $pong = Redis::connection('health')->ping();
            if ($pong === true || $pong === '+PONG' || $pong === 'PONG' || !empty($pong)) {
                $details['redis']['status'] = 'healthy';
                $redisHealthy = true;
            } else {
                $status = 'unhealthy';
                $details['redis'] = [
                    'status' => 'unhealthy',
                    'error' => 'Redis ping returned unexpected response',
                ];
            }
        } catch (Throwable $e) {
            $status = 'unhealthy';
            $details['redis'] = [
                'status' => 'unhealthy',
                'error' => 'Connection failed',
            ];
            Log::error('Health Check - Redis error: ' . $e->getMessage());
        }

        try {
            $root = (string) config('freshdesk_spool.root');
            $writablePath = is_dir($root) ? $root : dirname($root);
            $writable = is_dir($writablePath) && is_writable($writablePath);
            $details['freshdesk_spool'] = [
                'status' => $writable ? 'healthy' : 'unhealthy',
                'writable' => $writable,
                'free_bytes' => @disk_free_space($writablePath) ?: null,
            ];
            if (!$writable) {
                $status = 'unhealthy';
            }
        } catch (Throwable $e) {
            $status = 'unhealthy';
            $details['freshdesk_spool'] = [
                'status' => 'unhealthy',
                'error' => 'Unable to inspect durable spool',
            ];
        }

        $details['queue'] = [
            'status' => $redisHealthy ? 'healthy' : 'unhealthy',
            'connection' => config('queue.default'),
            'size' => null,
            'note' => 'Backlog counting is excluded from the lightweight health endpoint.',
        ];

        $statusCode = ($status === 'healthy') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'details' => $details,
        ], $statusCode);
    }

    /**
     * Check Database connection.
     * GET /api/health/db
     */
    public function checkDb(): JsonResponse
    {
        $status = 'healthy';
        $details = [
            'database' => ['status' => 'unknown'],
        ];

        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $details['database']['status'] = 'healthy';
            $details['database']['driver'] = DB::connection()->getDriverName();
        } catch (Throwable $e) {
            $status = 'unhealthy';
            $details['database'] = [
                'status' => 'unhealthy',
                'error' => 'Database connection failed',
            ];
            Log::error('Health Check - Database error: ' . $e->getMessage());
        }

        $statusCode = ($status === 'healthy') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'details' => $details,
        ], $statusCode);
    }
}
