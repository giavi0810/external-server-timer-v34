<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.rocketchat.webhook_url' => null,
            'services.rocketchat.url' => null,
            'services.rocketchat.user_id' => null,
            'services.rocketchat.token' => null,
            'rocketchat_audit.enabled' => false,
        ]);
    }
}
