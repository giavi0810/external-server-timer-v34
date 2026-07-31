<?php

namespace App\Services;

use App\Models\FreshdeskGroup;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FreshdeskApiService
{
    protected string $domain;
    protected string $apiKey;
    protected ?array $lastErrorContext = null;

    public function __construct()
    {
        $this->domain = (string) config('freshdesk.domain', '');
        $this->apiKey = (string) config('freshdesk.api_key', '');
    }

    /**
     * Resolve group name from group ID.
     * Priority: DB cache → API refresh → DB retry → fallback.
     */
    public function resolveGroupName(?string $groupId): ?string
    {
        if (empty($groupId)) {
            return null;
        }

        // 1. Check DB cache first (skip if cached name is temporary "Freshdesk Group ...")
        $fdGroup = FreshdeskGroup::where('group_id', $groupId)->first();
        if ($fdGroup && !empty($fdGroup->name) && !str_starts_with($fdGroup->name, 'Freshdesk Group ')) {
            return $fdGroup->name;
        }

        $mappedName = config("freshdesk.group_mapping.{$groupId}");
        if (is_string($mappedName) && $mappedName !== '') {
            return $mappedName;
        }

        Log::info("FreshdeskApiService: Group ID {$groupId} has no real name in DB. Triggering API refresh...", [
            'group_id' => $groupId,
            'existing_name_in_db' => $fdGroup?->name,
        ]);

        // 2. Try refreshing from Freshdesk API
        try {
            $this->refreshGroupMappings();

            // 3. Check DB again after refresh
            $fdGroup = FreshdeskGroup::where('group_id', $groupId)->first();
            if ($fdGroup && !empty($fdGroup->name) && !str_starts_with($fdGroup->name, 'Freshdesk Group ')) {
                return $fdGroup->name;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to refresh group mappings from Freshdesk API", [
                'error' => $e->getMessage(),
                'group_id' => $groupId,
            ]);
        }

        // 4. Fallback: return generic id
        return (string) $groupId;
    }


    /**
     * Resolve group ID from group name.
     * Priority: DB cache -> config fallback -> parse fallback name -> API refresh -> DB retry.
     */
    public function resolveGroupId(?string $groupName): ?string
    {
        if (empty($groupName)) {
            return null;
        }

        $normalizedGroupName = trim((string) $groupName);
        $fdGroup = FreshdeskGroup::where('name', $normalizedGroupName)->first();
        if ($fdGroup) {
            return $fdGroup->group_id;
        }

        $configMappings = config('freshdesk.group_mapping', []);
        foreach ($configMappings as $id => $name) {
            if (strcasecmp((string) $name, $normalizedGroupName) === 0) {
                return (string) $id;
            }
        }

        if (preg_match('/^Freshdesk Group (\d+)$/i', $normalizedGroupName, $matches)) {
            return $matches[1];
        }

        try {
            $this->refreshGroupMappings();
            $fdGroup = FreshdeskGroup::where('name', $normalizedGroupName)->first();
            if ($fdGroup) {
                return $fdGroup->group_id;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to refresh group mappings when resolving group ID", [
                'error' => $e->getMessage(),
                'group_name' => $groupName,
            ]);
        }

        return null;
    }

    /**
     * Refresh group mappings from Freshdesk API.
     * GET /api/v2/ticket_fields?type=default_group → parse choices → upsert freshdesk_groups.
     */
    public function refreshGroupMappings(): void
    {
        if (empty($this->domain) || empty($this->apiKey)) {
            Log::warning('Freshdesk API credentials not configured, skipping group refresh', [
                'domain' => $this->domain,
                'has_api_key' => !empty($this->apiKey),
            ]);
            return;
        }

        $url = "https://{$this->domain}/api/v2/ticket_fields?type=default_group";

        Log::info("FreshdeskApiService: Calling API to refresh groups", ['url' => $url]);

        $response = Http::withBasicAuth($this->apiKey, 'X')
            ->timeout(30)
            ->get($url);

        if (!$response->successful()) {
            Log::error("Freshdesk ticket_fields API request failed", [
                'url' => $url,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);
            return;
        }

        $fields = $response->json();
        if (!is_array($fields)) {
            Log::warning("Freshdesk ticket_fields API returned unexpected response format");
            return;
        }

        foreach ($fields as $field) {
            if (isset($field['name']) && $field['name'] === 'group') {
                $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];

                if (empty($choices)) {
                    Log::warning("Freshdesk group field choices is empty");
                    return;
                }

                $activeGroupIds = [];

                DB::transaction(function () use ($choices, &$activeGroupIds): void {
                    foreach ($choices as $groupName => $groupId) {
                        $normalizedGroupId = (string) $groupId;
                        $activeGroupIds[] = $normalizedGroupId;

                        FreshdeskGroup::updateOrCreate(
                            ['group_id' => $normalizedGroupId],
                            [
                                'name' => (string) $groupName,
                                'main_layer' => $this->detectMainLayer((string) $groupName),
                                'is_active' => true,
                            ]
                        );
                    }

                    // Mark missing groups as inactive on Freshdesk (preserve historical records)
                    if (!empty($activeGroupIds)) {
                        FreshdeskGroup::query()
                            ->where('is_active', true)
                            ->whereNotIn('group_id', $activeGroupIds)
                            ->update([
                                'is_active' => false,
                                'is_default_assignment' => false,
                                'updated_at' => now(),
                            ]);
                    }
                });

                Log::info("Group mappings successfully refreshed from Freshdesk API", [
                    'count' => count($choices),
                    'active_count' => count($activeGroupIds),
                    'inactive_count' => FreshdeskGroup::where('is_active', false)->count(),
                ]);
                break;
            }
        }
    }




    /**
     * Auto-detect main layer from group name.
     */
    protected function detectMainLayer(string $groupName): string
    {
        $name = strtolower($groupName);

        if (str_contains($name, 'l1') || str_contains($name, 'layer 1'))
            return 'L1';
        if (str_contains($name, 'l2') || str_contains($name, 'layer 2'))
            return 'L2';
        if (str_contains($name, 'l3') || str_contains($name, 'layer 3'))
            return 'L3';
        if (str_contains($name, 'l4') || str_contains($name, 'layer 4'))
            return 'L4';

        return 'L1';
    }

    /**
     * Fetch ticket details from Freshdesk API.
     */
    public function getTicket(int $ticketId): ?array
    {
        if (empty($this->domain) || empty($this->apiKey)) {
            return null;
        }

        $url = "https://{$this->domain}/api/v2/tickets/{$ticketId}";

        $response = Http::withBasicAuth($this->apiKey, 'X')
            ->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error("Failed to fetch ticket from Freshdesk", [
            'ticket_id' => $ticketId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    public function getLastErrorContext(): ?array
    {
        return $this->lastErrorContext;
    }

    /**
     * Update custom fields for a ticket.
     */
    public function updateTicketCustomFields(int $ticketId, array $customFields): bool
    {
        return $this->updateTicket($ticketId, [
            'custom_fields' => $customFields,
        ]);
    }

    /**
     * Create a new ticket on Freshdesk.
     */
    public function createTicket(array $payload): ?array
    {
        if (empty($this->domain) || empty($this->apiKey)) {
            $this->lastErrorContext = [
                'action' => 'create_ticket',
                'reason' => 'config_missing',
                'retryable' => false,
                'outcome_unknown' => false,
            ];
            Log::error("Failed to create ticket on Freshdesk", ['reason' => 'missing_credentials']);
            return null;
        }

        $url = "https://{$this->domain}/api/v2/tickets";

        try {
            $response = Http::withBasicAuth($this->apiKey, 'X')
                ->timeout(30)
                ->post($url, $payload);
        } catch (\Throwable $exception) {
            $this->lastErrorContext = [
                'action' => 'create_ticket',
                'reason' => 'network_exception',
                'retryable' => true,
                'outcome_unknown' => true,
                'error' => $exception->getMessage(),
            ];
            Log::error("Failed to create ticket on Freshdesk", ['error' => $exception->getMessage()]);
            return null;
        }

        if ($response->successful()) {
            $this->lastErrorContext = null;
            return $response->json();
        }

        $this->lastErrorContext = [
            'action' => 'create_ticket',
            'status' => $response->status(),
            'reason' => $this->mapStatusToReason($response->status()),
            'retryable' => $this->isRetryableStatus($response->status()),
            'outcome_unknown' => false,
        ];

        Log::error("Failed to create ticket on Freshdesk", [
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 400),
        ]);

        return null;
    }

    /**
     * Reconcile a POST operation by its deterministic marker before retrying.
     */
    public function findTicketByOperationMarker(string $marker): ?array
    {
        if (empty($this->domain) || empty($this->apiKey)) {
            throw new \RuntimeException('Freshdesk credentials are missing.');
        }

        $response = Http::withBasicAuth($this->apiKey, 'X')
            ->timeout(30)
            ->get("https://{$this->domain}/api/v2/search/tickets", [
                'query' => "\"tag:'{$marker}'\"",
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Freshdesk marker reconciliation failed (status={$response->status()})."
            );
        }

        $results = $response->json('results');
        return is_array($results) && isset($results[0]) ? $results[0] : null;
    }

    /**
     * Add a private note to a ticket on Freshdesk.
     */
    public function addTicketNote(int $ticketId, string $body, bool $private = true): bool
    {
        if (empty($this->domain) || empty($this->apiKey)) {
            Log::error("Failed to add note to ticket {$ticketId}", ['reason' => 'missing_credentials']);
            return false;
        }

        $url = "https://{$this->domain}/api/v2/tickets/{$ticketId}/notes";

        $payload = [
            'body' => $body,
            'private' => $private,
        ];

        try {
            $response = Http::withBasicAuth($this->apiKey, 'X')
                ->timeout(30)
                ->post($url, $payload);
        } catch (\Throwable $exception) {
            Log::error("Failed to add note to ticket {$ticketId}", ['error' => $exception->getMessage()]);
            return false;
        }

        if ($response->successful()) {
            return true;
        }

        Log::error("Failed to add note to ticket {$ticketId}", [
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 400),
        ]);

        return false;
    }

    /**
     * Append a tag to a ticket without removing existing tags.
     */
    public function addTagToTicket(int $ticketId, string $tag): bool
    {
        $ticket = $this->getTicket($ticketId);
        if (!is_array($ticket)) {
            Log::warning("Failed to add tag to ticket {$ticketId}", [
                'reason' => 'ticket_fetch_failed',
                'tag' => $tag,
            ]);
            return false;
        }

        $currentTags = is_array($ticket['tags'] ?? null) ? $ticket['tags'] : [];
        $normalizedTag = trim($tag);

        foreach ($currentTags as $existingTag) {
            if (strcasecmp(trim((string) $existingTag), $normalizedTag) === 0) {
                return true;
            }
        }

        $currentTags[] = $normalizedTag;

        return $this->updateTicket($ticketId, [
            'tags' => array_values($currentTags),
        ]);
    }

    /**
     * Replace prior numbered variants of a tag and append the next count.
     */
    public function addIncrementedTagToTicket(int $ticketId, string $baseTag): ?string
    {
        $ticket = $this->getTicket($ticketId);
        if (!is_array($ticket)) {
            Log::warning("Failed to add incremented tag to ticket {$ticketId}", [
                'reason' => 'ticket_fetch_failed',
                'tag' => $baseTag,
            ]);
            return null;
        }

        $currentTags = is_array($ticket['tags'] ?? null) ? $ticket['tags'] : [];
        $normalizedBaseTag = trim($baseTag);
        $pattern = '/^' . preg_quote($normalizedBaseTag, '/') . '(?:\s*\((\d+)\))?$/i';

        $filteredTags = [];
        $maxCount = 0;

        foreach ($currentTags as $existingTag) {
            $tagValue = trim((string) $existingTag);

            if (preg_match($pattern, $tagValue, $matches)) {
                $count = isset($matches[1]) ? max(1, (int) $matches[1]) : 1;
                $maxCount = max($maxCount, $count);
                continue;
            }

            $filteredTags[] = (string) $existingTag;
        }

        $newTag = sprintf('%s (%d)', $normalizedBaseTag, $maxCount + 1);
        $filteredTags[] = $newTag;

        $updated = $this->updateTicket($ticketId, [
            'tags' => array_values($filteredTags),
        ]);

        return $updated ? $newTag : null;
    }

    /**
     * Update ticket properties on Freshdesk.
     */
    public function updateTicket(int $ticketId, array $payload): bool
    {
        if (empty($this->domain) || empty($this->apiKey)) {
            $this->lastErrorContext = [
                'provider' => 'freshdesk',
                'action' => 'update_ticket',
                'ticket_id' => $ticketId,
                'error' => 'missing_credentials',
                'reason' => 'config_missing',
                'retryable' => false,
            ];
            Log::error("Failed to update ticket on Freshdesk", $this->lastErrorContext);
            return false;
        }

        $url = "https://{$this->domain}/api/v2/tickets/{$ticketId}";

        try {
            $response = Http::withBasicAuth($this->apiKey, 'X')
                ->timeout(30)
                ->put($url, $payload);
        } catch (\Throwable $exception) {
            $this->lastErrorContext = [
                'provider' => 'freshdesk',
                'action' => 'update_ticket',
                'ticket_id' => $ticketId,
                'url' => $url,
                'error' => $exception->getMessage(),
                'reason' => 'network_exception',
                'retryable' => true,
            ];

            Log::error("Failed to update ticket on Freshdesk", $this->lastErrorContext);
            return false;
        }

        if ($response->successful()) {
            $this->lastErrorContext = null;
            return true;
        }

        $this->lastErrorContext = [
            'provider' => 'freshdesk',
            'action' => 'update_ticket',
            'ticket_id' => $ticketId,
            'url' => $url,
            'status' => $response->status(),
            'reason' => $this->mapStatusToReason($response->status()),
            'retryable' => $this->isRetryableStatus($response->status()),
            'error_hint' => $this->extractErrorHint($response),
            'retry_after' => $response->header('Retry-After'),
            'body' => Str::limit($response->body(), 400),
        ];

        Log::error("Failed to update ticket on Freshdesk", $this->lastErrorContext);

        return false;
    }

    protected function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }

    protected function mapStatusToReason(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'ticket_not_found_or_no_access',
            408 => 'timeout',
            409 => 'conflict',
            422 => 'validation_error',
            425 => 'too_early',
            429 => 'rate_limited',
            500 => 'server_error',
            502 => 'bad_gateway',
            503 => 'service_unavailable',
            504 => 'gateway_timeout',
            default => 'http_error',
        };
    }

    protected function extractErrorHint(Response $response): ?string
    {
        $json = $response->json();
        if (!is_array($json)) {
            return null;
        }

        if (is_string($json['description'] ?? null)) {
            return Str::limit($json['description'], 200);
        }

        if (is_string($json['message'] ?? null)) {
            return Str::limit($json['message'], 200);
        }

        $errors = $json['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $first = $errors[0];
            if (is_string($first['message'] ?? null)) {
                return Str::limit($first['message'], 200);
            }
        }

        return null;
    }
}
