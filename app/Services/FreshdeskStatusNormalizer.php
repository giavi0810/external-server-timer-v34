<?php

namespace App\Services;

class FreshdeskStatusNormalizer
{
    public function canonicalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $statusMap = config('freshdesk.status_map', []);
        if (preg_match('/^\d+$/', $normalized) === 1) {
            $statusId = (int) $normalized;
            if (array_key_exists($statusId, $statusMap)) {
                return (string) $statusMap[$statusId];
            }
        }

        foreach ($statusMap as $canonical) {
            if (strcasecmp((string) $canonical, $normalized) === 0) {
                return (string) $canonical;
            }
        }

        return $normalized;
    }
}
