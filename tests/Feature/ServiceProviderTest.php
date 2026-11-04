<?php

namespace SocialDept\AtpAppview\Tests\Feature;

use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Profiles\ResolvedProfiles;
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

    public function test_per_viewer_reads_are_kept_for_one_request_or_job(): void
    {
        foreach ([Graph::class, NotificationPreferences::class, ResolvedProfiles::class] as $class) {
            $first = app($class);

            $this->assertSame($first, app($class), "{$class} is shared within a request.");

            $this->app->forgetScopedInstances();

            $this->assertNotSame($first, app($class), "{$class} starts empty in the next request.");
        }
    }
}
