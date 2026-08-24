<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class FreshdeskGroupRefreshFailedException extends RuntimeException
{
    public function __construct(public readonly string $groupId, Throwable $previous)
    {
        parent::__construct(
            "Failed to synchronize Freshdesk group {$groupId}: {$previous->getMessage()}",
            previous: $previous
        );
    }
}
