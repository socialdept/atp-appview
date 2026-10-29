<?php

namespace SocialDept\AtpAppview\Tests\Feature\Spaces;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Spaces\SpaceBlobUrls;
use SocialDept\AtpAppview\Spaces\SpaceGrants;
use SocialDept\AtpAppview\Spaces\SpaceRecordQuery;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Without Features::spaces(), no space tables, command, notification
 * routes, blob route or enter procedure exist, and following, entering,
 * querying a space or signing a blob URL refuses, naming the feature.
 */
class SpacesFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpSpacesServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', []);
        $app['config']->set('atp-appview.spaces.xrpc.enter', 'com.example.space.enter');
    }

    public function test_nothing_of_spaces_is_registered(): void
    {
        $this->artisan('migrate')->run();

        $this->assertFalse(Schema::hasTable('atp_spaces'));
        $this->assertFalse(Schema::hasTable('atp_space_records'));
        $this->assertFalse(Schema::hasTable('atp_space_grants'));
        $this->assertArrayNotHasKey('appview:spaces:sync', Artisan::all());
        $this->assertFalse(Route::has('atp-spaces.com.atproto.space.notifyWrite'));
        $this->assertFalse(Route::has(SpaceBlobUrls::ROUTE));
        $this->get('/atp-appview/space-blobs/did:plc:alice/bafkreihdwdcefgh4dqkjv67uzcmw7ojee6xedzdetojuzjevtenxquvyku')->assertNotFound();
        $this->postJson('/xrpc/com.example.space.enter', ['space' => 'at://did:plc:authority/space/com.example.forum/main', 'delegationToken' => 'a.b.c'])->assertStatus(501);
    }

    public function test_entering_a_space_refuses(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Features::spaces()');

        app(SpaceGrants::class)->enterFromDelegation(SpaceRef::make('did:plc:authority', 'com.example.forum', 'main'), 'did:plc:alice', 'a.b.c');
    }

    public function test_querying_a_space_refuses(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Features::spaces()');

        SpaceRecordQuery::in(SpaceRef::make('did:plc:authority', 'com.example.forum', 'main'))->visibleTo('did:plc:alice')->get();
    }

    public function test_signing_a_blob_url_refuses(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Features::spaces()');

        app(SpaceBlobUrls::class)->url(SpaceRef::make('did:plc:authority', 'com.example.forum', 'main'), 'did:plc:alice', 'bafkreihdwdcefgh4dqkjv67uzcmw7ojee6xedzdetojuzjevtenxquvyku', 'did:plc:alice');
    }

    public function test_following_a_space_refuses(): void
    {
        $space = SpaceRef::make('did:plc:authority', 'com.example.forum', 'main');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Features::spaces()');

        app(SpaceSync::class)->follow(new SpaceCredential($space, 'did:plc:alice', new SpaceToken(SpaceTokenType::Credential, [], ['exp' => time() + 600], 'a.b.c'), CredentialKey::generate()));
    }
}
