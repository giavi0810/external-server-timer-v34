<?php

namespace App\Exceptions;

use RuntimeException;

class FreshdeskGroupMissingException extends RuntimeException
{
    public function __construct(public readonly string $groupId)
    {
        parent::__construct("Freshdesk group {$groupId} was not present after synchronization.");
    }
}
