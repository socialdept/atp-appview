<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\IdentityRefresh;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Facades\Resolver;

class IdentityRefreshTest extends TestCase
{
    protected const DID = 'did:plc:alice';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.profiles.sources', [['collection' => 'app.bsky.actor.profile']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Identity::query()->insert(['did' => self::DID, 'handle' => 'old.test', 'pds' => 'https://old.example', 'active' => true, 'updated_at' => now()]);
    }

    protected function document(string $handle, string $pds): DidDocument
    {
        return DidDocument::fromArray([
            'id' => self::DID,
            'alsoKnownAs' => ["at://{$handle}"],
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $pds]],
        ]);
    }

    public function test_it_takes_the_pds_and_a_verified_handle_from_the_did_document_and_drops_the_cached_profile(): void
    {
        Cache::put('atp-appview:profile:app.bsky.actor.profile:self:'.self::DID, ['displayName' => 'Old'], 3600);
        Resolver::shouldReceive('clearDidCache', 'clearPdsCache')->with(self::DID);
        Resolver::shouldReceive('resolveDid')->with(self::DID, false)->andReturn($this->document('alice.test', 'https://new.example/'));
        Resolver::shouldReceive('handleToDid')->with('alice.test', false)->andReturn(self::DID);

        $result = app(IdentityRefresh::class)->refresh(self::DID);

        $this->assertSame(['pds' => ['https://old.example', 'https://new.example'], 'handle' => ['old.test', 'alice.test']], $result['changed']);
        $identity = Identity::query()->find(self::DID);
        $this->assertSame('alice.test', $identity->handle);
        $this->assertSame('https://new.example', $identity->pds);
        $this->assertNotNull($identity->pds_resolved_at);
        $this->assertNull(Cache::get('atp-appview:profile:app.bsky.actor.profile:self:'.self::DID), 'The fetched profile is dropped.');
    }

    public function test_a_handle_that_does_not_resolve_back_leaves_the_stored_one(): void
    {
        Resolver::shouldReceive('clearDidCache', 'clearPdsCache');
        Resolver::shouldReceive('resolveDid')->andReturn($this->document('impostor.test', 'https://old.example'));
        Resolver::shouldReceive('handleToDid')->with('impostor.test', false)->andThrow(new RuntimeException('no'));

        $result = app(IdentityRefresh::class)->refresh(self::DID);

        $this->assertNull($result['handle']);
        $this->assertSame([], $result['changed']);
        $this->assertSame('old.test', Identity::query()->find(self::DID)->handle);
    }
}
