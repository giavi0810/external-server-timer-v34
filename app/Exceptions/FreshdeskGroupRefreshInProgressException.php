<?php

namespace App\Exceptions;

use RuntimeException;

class FreshdeskGroupRefreshInProgressException extends RuntimeException
{
    public function __construct(public readonly string $groupId)
    {
        parent::__construct("Freshdesk group synchronization is already in progress for group {$groupId}.");
    }
}
