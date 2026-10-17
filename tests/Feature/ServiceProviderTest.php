<?php

namespace SocialDept\AtpAppview\Tests\Feature;

use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    public function test_it_merges_the_package_config(): void
    {
        $this->assertSame('backend', config('atp-appview.auth.mode'));
    }
}
