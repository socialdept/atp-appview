<?php

namespace SocialDept\AtpAppview\Tests\Feature;

use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;

class ConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // As an app's published config would set them before the package
        // registers: part of a global section, part of a feature section, and
        // a list. Testbench sets config after providers register, so register
        // again to merge as an app boots.
        config(['atp-appview' => [
            'ingest' => ['batch_size' => 5],
            'profiles' => ['merge' => 'field'],
            'views' => ['App\\Views\\ThingView'],
        ]]);
        (new AtpAppviewServiceProvider($this->app))->register();
    }

    public function test_a_section_the_app_sets_in_part_keeps_the_defaults_it_does_not_mention(): void
    {
        $this->assertSame(5, config('atp-appview.ingest.batch_size'));
        $this->assertTrue(config('atp-appview.ingest.webhook.enabled'), 'A setting the app never published still has its default.');

        $this->assertSame('field', config('atp-appview.profiles.merge'));
        $this->assertSame('app.bsky.actor.profile', config('atp-appview.profiles.sources.0.collection'));
    }

    public function test_feature_sections_load_from_their_own_files(): void
    {
        $this->assertSame('avatar-sm', config('atp-appview.images.presets.author'));
        $this->assertSame(500, config('atp-appview.backfill.chunk'));
    }

    public function test_a_list_the_app_sets_is_the_apps_alone(): void
    {
        $this->assertSame(['App\\Views\\ThingView'], config('atp-appview.views'));
    }
}
