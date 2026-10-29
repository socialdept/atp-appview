<?php

namespace SocialDept\AtpAppview\Tests\Feature\Spaces;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Without Features::spaces(), no space tables, command or notification
 * routes exist, and following a space refuses, naming the feature.
 */
class SpacesFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpSpacesServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', []);
    }

    public function test_nothing_of_spaces_is_registered(): void
    {
        $this->artisan('migrate')->run();

        $this->assertFalse(Schema::hasTable('atp_spaces'));
        $this->assertFalse(Schema::hasTable('atp_space_records'));
        $this->assertArrayNotHasKey('appview:spaces:sync', Artisan::all());
        $this->assertFalse(Route::has('atp-spaces.com.atproto.space.notifyWrite'));
    }

    public function test_following_a_space_refuses(): void
    {
        $space = SpaceRef::make('did:plc:authority', 'com.example.forum', 'main');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Features::spaces()');

        app(SpaceSync::class)->follow(new SpaceCredential($space, 'did:plc:alice', new SpaceToken(SpaceTokenType::Credential, [], ['exp' => time() + 600], 'a.b.c'), CredentialKey::generate()));
    }
}
