<?php

namespace SocialDept\AtpAppview\Tests\Feature\Profiles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Images\BlobUrls;
use SocialDept\AtpAppview\Models\Backfill;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class ProfilesTest extends TestCase
{
    protected const OWN = 'com.example.actor.profile';
    protected const BSKY = 'app.bsky.actor.profile';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.ingest.companions', [self::OWN, self::BSKY]);
        $app['config']->set('atp-appview.profiles.sources', [['collection' => self::OWN, 'fields' => ['pronouns']], ['collection' => self::BSKY]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Http::preventStrayRequests();
    }

    protected function profiles(): Profiles
    {
        return $this->app->make(Profiles::class);
    }

    protected function account(string $did, array $state = []): void
    {
        Identity::query()->insert(['did' => $did, 'handle' => substr($did, 8).'.test', 'pds' => 'https://pds.example', 'active' => true, 'updated_at' => now(), ...$state]);
    }

    protected function profile(string $did, string $collection, array $value): void
    {
        DB::table('atp_records')->insert([
            'uri' => "at://{$did}/{$collection}/self", 'did' => $did, 'collection' => $collection, 'rkey' => 'self',
            'cid' => 'bafy', 'record' => json_encode($value), 'rev' => '3l2aaaaaaaaaa', 'indexed_at' => now(), 'sort_at' => now(),
        ]);
    }

    protected function avatar(): array
    {
        return ['$type' => 'blob', 'ref' => ['$link' => 'bafyavatar'], 'mimeType' => 'image/png'];
    }

    public function test_the_first_source_with_a_record_is_the_whole_profile_and_nothing_falls_through(): void
    {
        $this->account('did:plc:alice');
        $this->profile('did:plc:alice', self::OWN, ['displayName' => 'Alice', 'pronouns' => 'she/her']);
        $this->profile('did:plc:alice', self::BSKY, ['displayName' => 'Alice elsewhere', 'description' => 'Another bio', 'avatar' => $this->avatar()]);

        $this->assertSame([
            'did' => 'did:plc:alice', 'handle' => 'alice.test',
            'displayName' => 'Alice', 'description' => null, 'avatar' => null, 'banner' => null,
            'pronouns' => 'she/her',
        ], $this->profiles()->for(['did:plc:alice'])['did:plc:alice']);
    }

    public function test_without_the_first_source_the_next_is_used_without_the_first_sources_fields(): void
    {
        $this->account('did:plc:bob');
        $this->profile('did:plc:bob', self::BSKY, ['displayName' => 'Bob', 'avatar' => $this->avatar()]);

        $profile = $this->profiles()->for(['did:plc:bob'])['did:plc:bob'];

        $this->assertSame('Bob', $profile['displayName']);
        $this->assertSame('https://img.infra.coop/blob/did:plc:bob/bafyavatar/p=avatar', $profile['avatar']);
        $this->assertArrayNotHasKey('pronouns', $profile);
    }

    public function test_an_embedded_author_gets_a_small_avatar_and_images_can_come_from_the_pds(): void
    {
        $this->account('did:plc:bob');
        $this->profile('did:plc:bob', self::BSKY, ['avatar' => $this->avatar()]);

        $this->assertSame('https://img.infra.coop/blob/did:plc:bob/bafyavatar/p=avatar-sm', $this->profiles()->basic(['did:plc:bob'])['did:plc:bob']['avatar']);

        config(['atp-appview.images.driver' => 'pds']);
        $this->app->forgetInstance(BlobUrls::class);

        $this->assertSame('https://pds.example/xrpc/com.atproto.sync.getBlob?did=did%3Aplc%3Abob&cid=bafyavatar', $this->profiles()->basic(['did:plc:bob'])['did:plc:bob']['avatar']);
    }

    public function test_with_no_record_anywhere_a_profile_is_the_did_and_handle(): void
    {
        $this->account('did:plc:carol');

        $this->assertSame(['did' => 'did:plc:carol', 'handle' => 'carol.test', 'displayName' => null, 'avatar' => null], $this->profiles()->basic(['did:plc:carol'])['did:plc:carol']);
    }

    public function test_merging_per_field_fills_gaps_from_later_sources(): void
    {
        config(['atp-appview.profiles.merge' => 'field']);
        $this->account('did:plc:alice');
        $this->profile('did:plc:alice', self::OWN, ['displayName' => 'Alice']);
        $this->profile('did:plc:alice', self::BSKY, ['displayName' => 'Alice elsewhere', 'description' => 'Another bio']);

        $profile = $this->profiles()->for(['did:plc:alice'])['did:plc:alice'];

        $this->assertSame('Alice', $profile['displayName']);
        $this->assertSame('Another bio', $profile['description']);
    }

    public function test_an_account_the_index_does_not_know_is_fetched_in_order_and_cached_its_absence_too(): void
    {
        Http::fake([
            '*collection=com.example.actor.profile*' => Http::response(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400),
            '*collection=app.bsky.actor.profile*' => Http::response(['uri' => 'at://did:plc:dave/app.bsky.actor.profile/self', 'cid' => 'bafy', 'value' => ['displayName' => 'Dave']]),
        ]);

        $this->assertSame('Dave', $this->profiles()->for(['did:plc:dave'])['did:plc:dave']['displayName']);
        $this->assertSame('Dave', $this->profiles()->for(['did:plc:dave'])['did:plc:dave']['displayName']);

        Http::assertSentCount(2);
    }

    public function test_a_failed_fetch_is_not_remembered_as_no_profile(): void
    {
        Http::fakeSequence('*')->push('down', 503)->push('down', 503)
            ->push(['uri' => 'x', 'cid' => 'y', 'value' => ['displayName' => 'Erin']]);

        $this->assertNull($this->profiles()->for(['did:plc:erin'])['did:plc:erin']['displayName']);
        $this->assertSame('Erin', $this->profiles()->for(['did:plc:erin'])['did:plc:erin']['displayName']);
    }

    public function test_while_a_profile_collection_is_backfilled_an_account_missing_from_the_index_is_fetched(): void
    {
        $this->account('did:plc:frank');
        Backfill::query()->create(['collection' => self::BSKY, 'source' => 'followed repos', 'started_at' => now()]);
        Http::fake([
            '*collection=com.example.actor.profile*' => Http::response(['error' => 'RecordNotFound'], 400),
            '*collection=app.bsky.actor.profile*' => Http::response(['uri' => 'x', 'cid' => 'y', 'value' => ['displayName' => 'Frank']]),
        ]);

        $this->assertSame('Frank', $this->profiles()->for(['did:plc:frank'])['did:plc:frank']['displayName']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'collection=com.example.actor.profile'));
    }

    public function test_profiles_found_in_the_index_never_read_backfill_progress(): void
    {
        foreach (['alice' => 'Alice', 'bob' => 'Bob'] as $name => $display) {
            $this->account("did:plc:{$name}");
            $this->profile("did:plc:{$name}", self::OWN, ['displayName' => $display]);
            $this->profile("did:plc:{$name}", self::BSKY, ['displayName' => "{$display} on Bluesky"]);
        }
        Http::fake();

        $read = 0;
        DB::listen(function ($query) use (&$read) {
            if (str_contains($query->sql, 'atp_backfill')) {
                $read++;
            }
        });

        $profiles = $this->profiles()->for(['did:plc:alice', 'did:plc:bob']);

        $this->assertSame(['Alice', 'Bob'], [$profiles['did:plc:alice']['displayName'], $profiles['did:plc:bob']['displayName']]);
        $this->assertSame(0, $read, 'Every account had a record in every source, so a backfill could change nothing.');
    }

    public function test_an_inactive_account_shows_only_its_did_and_handle(): void
    {
        $this->account('did:plc:gone', ['active' => false]);
        $this->profile('did:plc:gone', self::BSKY, ['displayName' => 'Gone']);

        $this->assertNull($this->profiles()->for(['did:plc:gone'])['did:plc:gone']['displayName']);
    }

    public function test_sources_and_their_fields_can_be_set_fluently(): void
    {
        $this->account('did:plc:alice');
        $this->profile('did:plc:alice', self::OWN, ['name' => 'Alice', 'bio' => 'Hello', 'pronouns' => 'she/her']);

        Profiles::source(self::OWN)->aliases(['displayName' => 'name'])->map('tagline', fn (array $record) => strtoupper($record['bio']));

        $profile = $this->profiles()->for(['did:plc:alice'])['did:plc:alice'];

        $this->assertSame('Alice', $profile['displayName']);
        $this->assertSame('she/her', $profile['pronouns']);
        $this->assertSame('HELLO', $profile['tagline']);
    }

    public function test_indexed_profiles_are_read_by_primary_key_one_query_per_source(): void
    {
        $this->account('did:plc:alice');
        $this->account('did:plc:bob');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->profiles()->for(['did:plc:alice', 'did:plc:bob']);
        $queries = array_column(DB::getQueryLog(), 'query');

        $profileQueries = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'atp_records')));
        $this->assertCount(2, $profileQueries);
        $this->assertStringContainsString('"uri" in', $profileQueries[0]);
    }
}
