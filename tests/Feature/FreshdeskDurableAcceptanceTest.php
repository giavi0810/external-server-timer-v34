<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FreshdeskDurableAcceptanceTest extends TestCase
{
    private string $spoolRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->spoolRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'v34-spool-http-test-'.bin2hex(random_bytes(8));
        config()->set('freshdesk_spool.enabled', true);
        config()->set('freshdesk_spool.root', $this->spoolRoot);
        config()->set('freshdesk_spool.require_directory_fsync', false);
        config()->set('freshdesk.basic_auth.username', 'test-user');
        config()->set('freshdesk.basic_auth.password', 'test-password');
        config()->set(
            'services.rocketchat.alert_state_path',
            $this->spoolRoot.DIRECTORY_SEPARATOR.'alerts'.DIRECTORY_SEPARATOR.'state.json'
        );
    }

    protected function tearDown(): void
    {
        $expectedPrefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'v34-spool-http-test-';
        $resolvedParent = realpath(dirname($this->spoolRoot));
        if ($resolvedParent && str_starts_with(
            $resolvedParent.DIRECTORY_SEPARATOR.basename($this->spoolRoot),
            $expectedPrefix
        )) {
            File::deleteDirectory($this->spoolRoot);
        }
        parent::tearDown();
    }

    public function test_webhook_returns_success_only_after_ready_file_exists(): void
    {
        $response = $this->withBasicAuth('test-user', 'test-password')
            ->postJson('/api/webhooks/freshdesk', [
                'ticket_id' => 18321,
                'event_type' => 'ticket_created',
                'event_timestamp' => '2026-07-30T08:42:30Z',
                'ticket_data' => [
                    'id' => 18321,
                    'status' => 'Open',
                    'priority' => 'High',
                    'updated_at' => '2026-07-30T08:42:30Z',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonStructure(['receipt_id', 'correlation_id']);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->spoolRoot.DIRECTORY_SEPARATOR.'ready',
                \FilesystemIterator::SKIP_DOTS
            )
        );
        $jsonFiles = iterator_to_array(new \CallbackFilterIterator(
            $files,
            fn ($file): bool => $file->isFile() && str_ends_with($file->getFilename(), '.json')
        ));
        $this->assertCount(1, $jsonFiles);
    }

    public function test_webhook_returns_503_when_required_directory_fsync_is_unavailable(): void
    {
        config()->set('freshdesk_spool.require_directory_fsync', true);
        config()->set('freshdesk_spool.fsync_dir_binary', $this->spoolRoot.'/missing-fsync-dir');

        $response = $this->withBasicAuth('test-user', 'test-password')
            ->postJson('/api/webhooks/freshdesk', [
                'ticket_id' => 18321,
                'event_type' => 'ticket_created',
                'event_timestamp' => '2026-07-30T08:42:30Z',
            ]);

        $response->assertStatus(503);
    }
}
