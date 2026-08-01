<?php

namespace App\Services\Queue;

final readonly class DispatchResult
{
    public function __construct(
        public int $dispatched = 0,
        public int $failures = 0,
    ) {}

    public function didWork(): bool
    {
        return $this->dispatched > 0;
    }

    public function failed(): bool
    {
        return $this->failures > 0;
    }
}
