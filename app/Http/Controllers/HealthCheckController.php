<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
            'queue' => ['status' => 'unknown'],
        ];

        // 1. Check Redis connection
        try {
            $pong = Redis::connection()->ping();
            if ($pong === true || $pong === '+PONG' || $pong === 'PONG' || !empty($pong)) {
                $details['redis']['status'] = 'healthy';
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
                'error' => $e->getMessage(),
            ];
            Log::error('Health Check - Redis error: ' . $e->getMessage());
        }

        // 2. Check Queue connection & pending size
        try {
            $queueSize = Queue::size();
            $details['queue'] = [
                'status' => 'healthy',
                'size' => $queueSize,
                'connection' => config('queue.default'),
            ];
        } catch (Throwable $e) {
            $status = 'unhealthy';
            $details['queue'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
            Log::error('Health Check - Queue error: ' . $e->getMessage());
        }

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
                'error' => $e->getMessage(),
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
