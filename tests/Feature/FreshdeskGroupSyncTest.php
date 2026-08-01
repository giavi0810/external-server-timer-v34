<?php

namespace Tests\Feature;

use App\Models\FreshdeskGroup;
use App\Services\FreshdeskGroupSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FreshdeskGroupSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'freshdesk.domain' => 'freshdesk.test',
            'freshdesk.api_key' => 'test-key',
        ]);
    }

    public function test_unknown_payload_group_refreshes_groups_without_deleting_old_records(): void
    {
        FreshdeskGroup::create([
            'group_id' => '100',
            'name' => 'L1 Old Group',
            'main_layer' => 'L1',
            'is_active' => true,
        ]);
        Http::fake([
            'https://freshdesk.test/api/v2/ticket_fields*' => Http::response([[
                'name' => 'group',
                'choices' => ['L2 New Group' => 200],
            ]]),
        ]);

        app(FreshdeskGroupSyncService::class)->ensurePayloadGroupsKnown([
            'ticket_data' => [
                'group_id' => 200,
                'group_name' => 'L2 New Group',
            ],
        ]);

        $this->assertDatabaseHas('freshdesk_groups', [
            'group_id' => '200',
            'name' => 'L2 New Group',
            'main_layer' => 'L2',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('freshdesk_groups', [
            'group_id' => '100',
            'is_active' => false,
        ]);
        $this->assertSame(2, FreshdeskGroup::query()->count());
        Http::assertSentCount(1);
    }

    public function test_known_active_group_does_not_call_freshdesk(): void
    {
        FreshdeskGroup::create([
            'group_id' => '200',
            'name' => 'L2 Known Group',
            'main_layer' => 'L2',
            'is_active' => true,
        ]);
        Http::fake();

        app(FreshdeskGroupSyncService::class)->ensurePayloadGroupsKnown([
            'ticket_data' => ['group_id' => '200'],
        ]);

        Http::assertNothingSent();
    }

    public function test_failed_group_refresh_is_reported_for_spool_retry(): void
    {
        Http::fake([
            'https://freshdesk.test/api/v2/ticket_fields*' => Http::response([], 503),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        app(FreshdeskGroupSyncService::class)->ensurePayloadGroupsKnown([
            'ticket_data' => ['group_id' => '300'],
        ]);
    }
}
