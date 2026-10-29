<?php

namespace SocialDept\AtpAppview\Tests\Feature;

use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    public function test_it_merges_the_package_config(): void
    {
        $this->assertSame('frontend', config('atp-appview.auth.mode'));
    }
}
