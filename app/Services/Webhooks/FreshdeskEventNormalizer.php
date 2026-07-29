<?php

namespace App\Services\Webhooks;

use App\Exceptions\InvalidWebhookPayloadException;
use App\Models\TicketEvent;
use Carbon\Carbon;

class FreshdeskEventNormalizer
{
    private const CUSTOM_FIELD_PREFIXES = [
        'cf_sla_mode',
        'cf_number_of_due_date_changes',
        'cf_processing_phase',
        'cf_change_due_reason',
    ];

    public function normalize(array $event): array
    {
        $ticketData = $this->resolveTicketData($event);
        $eventType = (string) ($event['event_type'] ?? '');
        $eventTimestamp = $event['event_timestamp'] ?? null;

        if ($eventTimestamp === null || $eventTimestamp === '') {
            throw new InvalidWebhookPayloadException('event_timestamp is required');
        }

        if (in_array($eventType, [
            TicketEvent::EVENT_AGENT_REPLIED,
            TicketEvent::EVENT_REQUESTER_REPLIED,
        ], true) && ! empty($event['conversation_data']['updated_at'])) {
            $eventTimestamp = $event['conversation_data']['updated_at'];
        } elseif (! empty($ticketData['updated_at'])) {
            $eventTimestamp = $ticketData['updated_at'];
        }

        $ticketData['status'] = $this->normalizeStatus($ticketData['status'] ?? null);
        $ticketData['priority'] = $this->normalizePriority($ticketData['priority'] ?? null);

        if (array_key_exists('group_id', $ticketData) && $ticketData['group_id'] !== null) {
            if (! is_scalar($ticketData['group_id'])) {
                throw new InvalidWebhookPayloadException('ticket_data.group_id must be a scalar value');
            }

            $ticketData['group_id'] = trim((string) $ticketData['group_id']);
            if ($ticketData['group_id'] === '') {
                $ticketData['group_id'] = null;
            }
        }

        if (array_key_exists('group_name', $ticketData)
            && $ticketData['group_name'] !== null
            && ! is_string($ticketData['group_name'])) {
            throw new InvalidWebhookPayloadException('ticket_data.group_name must be a string');
        }

        $ticketData['custom_fields'] = $this->extractWhitelistedCustomFields($event, $ticketData);

        return array_merge($event, [
            'ticket_data' => $ticketData,
            'changes' => $this->normalizeChanges($event['changes'] ?? []),
            'event_timestamp' => $this->normalizeTimestamp($eventTimestamp),
        ]);
    }

    private function resolveTicketData(array $event): array
    {
        $candidates = [
            $event['ticket_data'] ?? null,
            $event['raw_payload']['ticket'] ?? null,
            $event['ticket'] ?? null,
        ];

        foreach ($candidates as $ticketData) {
            if ($ticketData === null) {
                continue;
            }

            if (! is_array($ticketData)) {
                throw new InvalidWebhookPayloadException('ticket_data must be an object');
            }

            if ($ticketData !== []) {
                return $ticketData;
            }
        }

        return [];
    }

    private function extractWhitelistedCustomFields(array $event, array $ticketData): array
    {
        $customFields = $event['raw_payload']['ticket']['custom_fields']
            ?? ($event['raw_payload']['custom_fields'] ?? null)
            ?? ($event['ticket_data']['custom_fields'] ?? null)
            ?? ($event['ticket']['custom_fields'] ?? null)
            ?? ($ticketData['custom_fields'] ?? []);

        $normalized = $this->normalizeCustomFieldShape($customFields);

        return array_filter(
            $normalized,
            fn (mixed $value, string $key): bool => $this->isWhitelistedCustomField($key),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function normalizeCustomFieldShape(mixed $customFields): array
    {
        if ($customFields === null || $customFields === []) {
            return [];
        }

        if (! is_array($customFields)) {
            throw new InvalidWebhookPayloadException('custom_fields must be an object or a list');
        }

        if (! array_is_list($customFields)) {
            $normalized = [];
            foreach ($customFields as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidWebhookPayloadException('custom_fields object keys must be strings');
                }
                $normalized[$key] = $value;
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($customFields as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidWebhookPayloadException("custom_fields.{$index} must be an object");
            }

            $name = $item['name'] ?? $item['key'] ?? $item['field'] ?? null;
            if (! is_string($name) || $name === '') {
                throw new InvalidWebhookPayloadException("custom_fields.{$index} must contain a string name");
            }

            $normalized[$name] = $item['value'] ?? null;
        }

        return $normalized;
    }

    private function isWhitelistedCustomField(string $key): bool
    {
        foreach (self::CUSTOM_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeChanges(mixed $changes): array
    {
        if ($changes === null) {
            return [];
        }

        if (! is_array($changes)) {
            throw new InvalidWebhookPayloadException('changes must be a list');
        }

        if (! array_is_list($changes)) {
            throw new InvalidWebhookPayloadException('changes must be a list');
        }

        return array_map(function (mixed $change, int $index): array {
            if (! is_array($change)) {
                throw new InvalidWebhookPayloadException("changes.{$index} must be an object");
            }

            $field = $change['field'] ?? null;
            if ($field === 'status_details') {
                $field = 'status';
                $change['old_value'] = $change['old_status_name']
                    ?? $this->extractChangeValue($change['old_value'] ?? null);
                $change['new_value'] = $change['new_status_name']
                    ?? $this->extractChangeValue($change['new_value'] ?? null);
            }

            if ($field === 'status') {
                $change['old_value'] = $this->normalizeStatus($change['old_value'] ?? null);
                $change['new_value'] = $this->normalizeStatus($change['new_value'] ?? null);
            } elseif ($field === 'priority') {
                $change['old_value'] = $this->normalizePriority($change['old_value'] ?? null);
                $change['new_value'] = $this->normalizePriority($change['new_value'] ?? null);
            } elseif ($field === 'group_id') {
                $change['old_value'] = $this->normalizeNullableScalar(
                    $change['old_value'] ?? null,
                    "changes.{$index}.old_value"
                );
                $change['new_value'] = $this->normalizeNullableScalar(
                    $change['new_value'] ?? null,
                    "changes.{$index}.new_value"
                );
            }

            if ($field !== null && ! is_string($field)) {
                throw new InvalidWebhookPayloadException("changes.{$index}.field must be a string");
            }

            $change['field'] = $field;

            return $change;
        }, $changes, array_keys($changes));
    }

    private function normalizeStatus(mixed $value): mixed
    {
        $value = $this->extractChangeValue($value);

        return is_numeric($value) ? config("freshdesk.status_map.{$value}", $value) : $value;
    }

    private function normalizePriority(mixed $value): mixed
    {
        $value = $this->extractChangeValue($value);

        return is_numeric($value) ? config("freshdesk.priority_map.{$value}", $value) : $value;
    }

    private function extractChangeValue(mixed $value): mixed
    {
        return is_array($value) ? ($value['name'] ?? $value['id'] ?? null) : $value;
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! $value instanceof \DateTimeInterface) {
            throw new InvalidWebhookPayloadException('event_timestamp must be a valid date');
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable $exception) {
            throw new InvalidWebhookPayloadException(
                'event_timestamp must be a valid date',
                previous: $exception
            );
        }
    }

    private function normalizeNullableScalar(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new InvalidWebhookPayloadException("{$field} must be a scalar value");
        }

        return (string) $value;
    }
}
