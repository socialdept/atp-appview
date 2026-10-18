<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;

class TapWebhookDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-appview.ingest.password', 'secret');
    }

    public function test_the_route_only_exists_when_enabled(): void
    {
        $this->postJson('/atp-appview/tap', ['events' => []], ['Authorization' => 'Basic '.base64_encode('admin:secret')])->assertNotFound();
    }
}
