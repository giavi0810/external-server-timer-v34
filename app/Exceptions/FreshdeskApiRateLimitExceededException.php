<?php

namespace App\Exceptions;

use RuntimeException;

class FreshdeskApiRateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly int $limit,
        public readonly int $windowSeconds,
        public readonly string $action
    ) {
        parent::__construct(
            "Freshdesk API rate limit reached for {$action}; retry_after={$retryAfterSeconds}"
        );
    }
}
