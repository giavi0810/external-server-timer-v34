<?php

namespace App\Services\Admin;

use Carbon\CarbonImmutable;

class SystemLogReader
{
    public const PER_PAGE = 50;

    private const MAX_ENTRY_BYTES = 1024 * 1024;

    private const READ_CHUNK_BYTES = 64 * 1024;

    private const LEVELS = [
        'EMERGENCY',
        'ALERT',
        'CRITICAL',
        'ERROR',
        'WARNING',
        'NOTICE',
        'INFO',
        'DEBUG',
    ];

    /**
     * Search one daily log file from newest to oldest with bounded memory.
     *
     * @param  array{query?: string, level?: string, component?: string, time_from?: string, time_to?: string, page?: int}  $filters
     * @return array<string, mixed>
     */
    public function search(?string $path, array $filters = []): array
    {
        $query = trim((string) ($filters['query'] ?? ''));
        $level = strtoupper(trim((string) ($filters['level'] ?? '')));
        $component = strtolower(trim((string) ($filters['component'] ?? '')));
        $timeFrom = $this->normalizeTime((string) ($filters['time_from'] ?? ''), '00:00:00');
        $timeTo = $this->normalizeTime((string) ($filters['time_to'] ?? ''), '23:59:59');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $statistics = array_fill_keys(self::LEVELS, 0);
        $entries = [];
        $total = 0;
        $scannedBytes = 0;

        if ($path !== null && is_file($path)) {
            $scannedBytes = (int) (@filesize($path) ?: 0);
            foreach ($this->entriesNewestFirst($path) as $entry) {
                $entryTime = $entry['timestamp_value']->format('H:i:s');
                if ($entryTime < $timeFrom || $entryTime > $timeTo) {
                    continue;
                }

                $statistics[$entry['level']] = ($statistics[$entry['level']] ?? 0) + 1;
                if (! $this->matchesLevel($entry['level'], $level)
                    || ($component !== '' && $entry['component'] !== $component)
                    || ! $this->matchesQuery($entry, $query)) {
                    continue;
                }

                if ($total >= $offset && count($entries) < self::PER_PAGE) {
                    unset($entry['timestamp_value'], $entry['searchable']);
                    $entries[] = $entry;
                }
                $total++;
            }
        }

        return [
            'entries' => $entries,
            'total' => $total,
            'statistics' => $statistics,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
            'scanned_files' => $path !== null && is_file($path) ? 1 : 0,
            'scanned_bytes' => $scannedBytes,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
        ];
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function entriesNewestFirst(string $path): \Generator
    {
        $continuationLines = [];
        $continuationBytes = 0;
        $detailsTruncated = false;

        foreach ($this->linesNewestFirst($path) as $line) {
            $header = $this->parseHeader($line, basename($path));
            if ($header === null) {
                $continuationLines[] = $line;
                $continuationBytes += strlen($line) + 1;

                while ($continuationBytes > self::MAX_ENTRY_BYTES && $continuationLines !== []) {
                    $removed = array_shift($continuationLines);
                    $continuationBytes -= strlen($removed) + 1;
                    $detailsTruncated = true;
                }

                continue;
            }

            $details = $line;
            if ($continuationLines !== []) {
                $details .= "\n".implode("\n", array_reverse($continuationLines));
            }
            if ($detailsTruncated) {
                $details .= "\n[Chi tiết đã được rút gọn vì một bản ghi vượt quá 1 MB]";
            }

            $header['raw'] = mb_strcut($details, 0, self::MAX_ENTRY_BYTES);
            yield $this->prepareEntry($header);

            $continuationLines = [];
            $continuationBytes = 0;
            $detailsTruncated = false;
        }
    }

    /** @return \Generator<int, string> */
    private function linesNewestFirst(string $path): \Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            $position = (int) (@filesize($path) ?: 0);
            $buffer = '';
            $skipTrailingEmptyLine = true;

            while ($position > 0) {
                $length = min(self::READ_CHUNK_BYTES, $position);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if ($chunk === false) {
                    break;
                }

                $buffer = $chunk.$buffer;
                $lines = explode("\n", $buffer);
                $buffer = array_shift($lines) ?? '';

                for ($index = count($lines) - 1; $index >= 0; $index--) {
                    $line = rtrim($lines[$index], "\r");
                    if ($skipTrailingEmptyLine && $line === '') {
                        $skipTrailingEmptyLine = false;

                        continue;
                    }
                    $skipTrailingEmptyLine = false;
                    yield $line;
                }
            }

            if ($buffer !== '') {
                yield rtrim($buffer, "\r");
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed>|null */
    private function parseHeader(string $line, string $source): ?array
    {
        if (! preg_match(
            '/^\[(?<timestamp>\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+(?<environment>[^.\s]+)\.(?<level>[A-Z]+):\s*(?<message>.*)$/u',
            $line,
            $matches
        )) {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($matches['timestamp']);
        } catch (\Throwable) {
            return null;
        }

        return [
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'timestamp_value' => $timestamp,
            'environment' => $matches['environment'],
            'level' => strtoupper($matches['level']),
            'message_raw' => trim($matches['message']),
            'source' => $source,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function prepareEntry(array $entry): array
    {
        $entry['component'] = $this->detectComponent($entry['raw']);
        $entry['identifiers'] = $this->extractIdentifiers($entry['raw']);
        $entry['message'] = $this->summarize($this->redact($entry['message_raw']));
        $entry['details'] = $this->redact($entry['raw']);
        $entry['searchable'] = mb_strtolower($entry['raw']);
        unset($entry['message_raw'], $entry['raw']);

        return $entry;
    }

    private function normalizeTime(string $value, string $default): string
    {
        $value = trim($value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return $value.($default === '23:59:59' ? ':59' : ':00');
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value)) {
            return $value;
        }

        return $default;
    }

    private function matchesLevel(string $entryLevel, string $filter): bool
    {
        if ($filter === '') {
            return true;
        }
        if ($filter === 'ERRORS') {
            return in_array($entryLevel, ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'], true);
        }

        return $entryLevel === $filter;
    }

    /** @param array<string, mixed> $entry */
    private function matchesQuery(array $entry, string $query): bool
    {
        if ($query === '') {
            return true;
        }

        preg_match_all('/"([^"]+)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $term = trim((string) ($match[1] !== '' ? $match[1] : $match[2]));
            if ($term === '') {
                continue;
            }

            if (str_contains($term, ':')) {
                [$field, $value] = array_pad(explode(':', $term, 2), 2, '');
                $field = strtolower($field);
                $value = mb_strtolower(trim($value));

                if ($value === '') {
                    return false;
                }
                if (array_key_exists($field, $entry['identifiers'])) {
                    if (! str_contains(mb_strtolower((string) $entry['identifiers'][$field]), $value)) {
                        return false;
                    }

                    continue;
                }
                if ($field === 'level' && mb_strtolower($entry['level']) === $value) {
                    continue;
                }
                if ($field === 'component' && mb_strtolower($entry['component']) === $value) {
                    continue;
                }
            }

            if (! str_contains($entry['searchable'], mb_strtolower($term))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function extractIdentifiers(string $raw): array
    {
        $identifiers = [];
        foreach (['ticket_id', 'receipt_id', 'correlation_id', 'event_id'] as $field) {
            if (preg_match('/["\']?'.preg_quote($field, '/').'["\']?\s*[:=]\s*["\']?([a-z0-9_-]+)/i', $raw, $match)) {
                $identifiers[$field] = $match[1];
            }
        }

        return $identifiers;
    }

    private function detectComponent(string $raw): string
    {
        $value = mb_strtolower($raw);

        return match (true) {
            str_contains($value, 'sla'), str_contains($value, 'timer'), str_contains($value, 'duedate') => 'sla',
            str_contains($value, 'queue'), str_contains($value, 'dispatcher'), str_contains($value, 'outbox') => 'queue',
            str_contains($value, 'redis') => 'redis',
            str_contains($value, 'postgres'), str_contains($value, 'pgsql'), str_contains($value, 'sqlstate') => 'postgresql',
            str_contains($value, 'rocket.chat'), str_contains($value, 'rocketchat') => 'rocketchat',
            str_contains($value, 'freshdesk'), str_contains($value, 'webhook') => 'freshdesk',
            default => 'application',
        };
    }

    private function summarize(string $message): string
    {
        $firstLine = trim(strtok($message, "\n") ?: $message);
        $summary = preg_replace('/\s+\{.*$/u', '', $firstLine) ?? $firstLine;

        return mb_strlen($summary) > 300 ? mb_substr($summary, 0, 297).'...' : $summary;
    }

    private function redact(string $value): string
    {
        $sensitiveKeys = 'password|passwd|token|api[_-]?key|authorization|cookie|secret';
        $value = preg_replace(
            '/(["\'](?:'.$sensitiveKeys.')["\']\s*:\s*)["\'][^"\']*["\']/iu',
            '$1"***"',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/\b('.$sensitiveKeys.')\s*=\s*([^\s,;]+)/iu',
            '$1=***',
            $value
        ) ?? $value;

        return preg_replace('/\bAuthorization:\s*[^\r\n]+/iu', 'Authorization: ***', $value) ?? $value;
    }
}
