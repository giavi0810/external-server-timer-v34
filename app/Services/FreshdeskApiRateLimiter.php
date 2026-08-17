<?php

namespace App\Services;

use App\Exceptions\FreshdeskApiRateLimitExceededException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FreshdeskApiRateLimiter
{
    public function __construct(private readonly RedisFactory $redis) {}

    public function acquire(string $action): void
    {
        if (! config('freshdesk.api_rate_limit.enabled', true)) {
            return;
        }

        $limit = max(1, (int) config('freshdesk.api_rate_limit.max_requests', 50));
        $windowSeconds = max(1, (int) config('freshdesk.api_rate_limit.window_seconds', 60));
        $connection = (string) config('freshdesk.api_rate_limit.redis_connection', 'default');
        $key = (string) config('freshdesk.api_rate_limit.key', 'freshdesk:api:global');
        $member = (string) Str::uuid();

        $result = $this->redis
            ->connection($connection)
            ->eval(
                $this->slidingWindowScript(),
                1,
                $key,
                $windowSeconds * 1000,
                $limit,
                $member
            );

        $allowed = (int) ($result[0] ?? 0) === 1;
        if ($allowed) {
            return;
        }

        $retryAfterMilliseconds = max(1, (int) ($result[2] ?? ($windowSeconds * 1000)));
        $retryAfterSeconds = (int) ceil($retryAfterMilliseconds / 1000);

        Log::info('Freshdesk API request deferred by global rate limit', [
            'action' => $action,
            'limit' => $limit,
            'window_seconds' => $windowSeconds,
            'retry_after_seconds' => $retryAfterSeconds,
        ]);

        throw new FreshdeskApiRateLimitExceededException(
            $retryAfterSeconds,
            $limit,
            $windowSeconds,
            $action
        );
    }

    private function slidingWindowScript(): string
    {
        return <<<'LUA'
local redis_time = redis.call('TIME')
local now_ms = (tonumber(redis_time[1]) * 1000) + math.floor(tonumber(redis_time[2]) / 1000)
local window_ms = tonumber(ARGV[1])
local max_requests = tonumber(ARGV[2])
local member = ARGV[3]
local cutoff_ms = now_ms - window_ms

redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', cutoff_ms)

local current_count = redis.call('ZCARD', KEYS[1])
if current_count < max_requests then
    redis.call('ZADD', KEYS[1], now_ms, member)
    redis.call('PEXPIRE', KEYS[1], window_ms + 1000)

    return {1, max_requests - current_count - 1, 0}
end

local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
local retry_after_ms = window_ms
if oldest[2] ~= nil then
    retry_after_ms = math.max(1, window_ms - (now_ms - tonumber(oldest[2])))
end

return {0, 0, retry_after_ms}
LUA;
    }
}
