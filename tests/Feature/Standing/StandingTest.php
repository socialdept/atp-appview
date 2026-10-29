<?php

namespace SocialDept\AtpAppview\Tests\Feature\Standing;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpAppview\Standing\RefreshStanding;
use SocialDept\AtpAppview\Standing\Standing;
use SocialDept\AtpAppview\Standing\Verification;
use SocialDept\AtpAppview\Standing\Verifiers\LabelVerifier;
use SocialDept\AtpAppview\Standing\Verifiers\RecordVerifier;
use SocialDept\AtpAppview\Tests\Fixtures\Standing\CommunityIssuers;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpCbor\Core\CID;
use SocialDept\AtpCbor\Crypto\Secp256k1Keypair;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Http\Response;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Facades\Microcosm;
use SocialDept\AtpSupport\Facades\Resolver;
use SocialDept\AtpSupport\Microcosm\ConstellationClient;
use SocialDept\AtpSupport\Microcosm\Data\GetBacklinksResponse;
use SocialDept\AtpSupport\Microcosm\MicrocosmException;

/**
 * Standing: metrics from the index, Constellation and repos, verification by
 * records (proven against the issuer's repo) and by labels, scores, and the
 * fields profiles gain. Reads never wait on the network.
 */
class StandingTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const DAVE = 'did:plc:dave';
    protected const ISSUER = 'did:plc:issuer';
    protected const STRANGER = 'did:plc:stranger';
    protected const LABELER = 'did:plc:labeler';
    protected const THING = 'com.example.thing';
    protected const LIKE = 'com.example.like';
    protected const FOLLOW = 'com.example.follow';
    protected const VERIFY = 'com.example.verification';
    protected const PROFILE = 'com.example.actor.profile';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.profiles.sources', [['collection' => self::PROFILE]]);
        $app['config']->set('atp-appview.ingest.collections', [self::THING, self::LIKE, self::FOLLOW, self::VERIFY, self::PROFILE]);
        $app['config']->set('atp-appview.features', [Features::moderation(), Features::standing()]);
        $app['config']->set('atp-appview.standing', [
            'metrics' => [
                'likes' => ['subject' => 'record', 'source' => 'index', 'link' => self::LIKE.':subject.uri'],
                'follows' => ['subject' => 'account', 'source' => 'index', 'link' => self::FOLLOW.':subject'],
                'followers' => ['subject' => 'account', 'source' => 'backlinks', 'link' => 'app.bsky.graph.follow:subject'],
                'following' => ['subject' => 'account', 'source' => 'repo', 'collection' => 'app.bsky.graph.follow', 'cap' => 150],
            ],
            'verification' => [
                'policy' => 'any',
                'verifiers' => [
                    ['class' => RecordVerifier::class, 'collection' => self::VERIFY, 'issuers' => [self::ISSUER], 'prove' => false],
                ],
            ],
            'scores' => [
                'account' => [
                    'verified' => 25,
                    'followers' => ['weight' => 10, 'transform' => 'log10'],
                    'ratio' => ['metric' => 'followers', 'transform' => 'ratio:following', 'weight' => 1],
                    'handle' => ['*.team' => 50],
                ],
                'record' => [
                    'likes' => ['weight' => 1, 'transform' => 'log10'],
                    'author' => 0.5,
                ],
            ],
            'fresh' => ['metrics' => 86400, 'verification' => 21600, 'retry' => 900],
            'batch' => 25,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Constellation's client is Guzzle, not Laravel's HTTP client, so it
        // is mocked rather than faked, and nothing reaches the network.
        Http::preventStrayRequests();
        // Profiles are read from Slingshot through a pool, which throws a
        // stray request on Laravel 12, so the read fails as a connection.
        Http::fake(['slingshot.microcosm.blue/*' => Http::failedConnection()]);
        $this->constellation(99);
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example')->byDefault();
        $atp = Atp::fake();
        $atp->stub('com.atproto.repo.listRecords', ['records' => array_fill(0, 100, ['uri' => 'at://x/app.bsky.graph.follow/a', 'cid' => 'c', 'value' => []]), 'cursor' => 'next']);
        $this->artisan('migrate')->run();
        $this->travelTo('2026-10-21 12:00:00');
    }

    protected ?ConstellationClient $constellation = null;

    /**
     * Constellation answering every count with $count, or failing, and
     * listing these backlinks.
     *
     * @param  array<int, array{did: string, collection: string, rkey: string}>  $backlinks
     */
    protected function constellation(?int $count, array $backlinks = []): void
    {
        $client = \Mockery::mock(ConstellationClient::class);
        $count === null
            ? $client->shouldReceive('getBacklinksCount')->andThrow(MicrocosmException::requestFailed('/count', 'down'))
            : $client->shouldReceive('getBacklinksCount')->andReturn($count);
        $client->shouldReceive('getBacklinks')->andReturn(GetBacklinksResponse::fromArray(['total' => count($backlinks), 'records' => $backlinks]));

        // Mockery answers with the first matching expectation, so a later call
        // swaps the client behind one expectation rather than adding another.
        $first = $this->constellation === null;
        $this->constellation = $client;

        if ($first) {
            Microcosm::shouldReceive('constellation')->andReturnUsing(fn () => $this->constellation);
        }
    }

    protected function index(string $did, string $collection, string $rkey, array $record): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, RecordAction::Create, "bafy-{$did}-{$rkey}", '3l2aaaaaaaaaa', $record + ['createdAt' => '2026-10-20T00:00:00Z'], true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function account(string $did, string $handle, ?string $name = null): void
    {
        DB::table('atp_identities')->insert(['did' => $did, 'handle' => $handle, 'active' => true, 'updated_at' => now()]);

        if ($name !== null) {
            $this->index($did, self::PROFILE, 'self', ['displayName' => $name]);
        }
    }

    protected function standing(): Standing
    {
        return app(Standing::class);
    }

    public function test_metrics_come_from_the_index_constellation_and_repos(): void
    {
        $thing = $this->index(self::ALICE, self::THING, 't1', ['text' => 'hi']);
        $this->index(self::BOB, self::LIKE, 'l1', ['subject' => ['uri' => $thing]]);
        $this->index(self::CAROL, self::LIKE, 'l2', ['subject' => ['uri' => $thing]]);
        $this->index(self::BOB, self::FOLLOW, 'f1', ['subject' => self::ALICE]);

        $this->standing()->refresh([self::ALICE, $thing]);

        $this->assertSame([self::ALICE => ['follows' => 1, 'followers' => 99, 'following' => 150]], $this->standing()->metricsFor([self::ALICE]), 'Following stops at its cap.');
        $this->assertSame([$thing => ['likes' => 2]], $this->standing()->metricsFor([$thing]));
    }

    public function test_reads_never_wait_on_the_network_and_queue_one_refresh(): void
    {
        Queue::fake();

        $this->assertSame([self::ALICE => ['follows' => null, 'followers' => null, 'following' => null]], $this->standing()->metricsFor([self::ALICE]));
        $this->standing()->metricsFor([self::ALICE]);
        $this->assertSame(Verification::NONE, $this->standing()->verification([self::ALICE])[self::ALICE]->status);

        Queue::assertPushed(RefreshStanding::class, 1);
        Microcosm::shouldNotHaveReceived('constellation');
    }

    public function test_an_unknown_value_keeps_what_was_there_and_is_tried_again_sooner(): void
    {
        $this->standing()->refresh([self::ALICE]);
        $this->constellation(null);
        $this->travel(2)->days();

        $this->standing()->refresh([self::ALICE]);

        $this->assertSame(99, $this->standing()->metricsFor([self::ALICE])[self::ALICE]['followers']);
        $this->assertSame('2026-10-23 12:15:00', DB::table('atp_standing')->where('subject', self::ALICE)->where('kind', 'followers')->value('stale_at'));
    }

    public function test_record_verification_needs_a_trusted_issuer_and_matching_fields(): void
    {
        $this->account(self::ALICE, 'alice.test', 'Alice');
        $this->account(self::BOB, 'bob.test', 'Bob');
        $this->account(self::CAROL, 'carol.test', 'Carol');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice']);
        $this->index(self::ISSUER, self::VERIFY, 'v2', ['subject' => self::BOB, 'handle' => 'bob.test', 'displayName' => 'Bobby']);
        $this->index(self::STRANGER, self::VERIFY, 'v3', ['subject' => self::CAROL, 'handle' => 'carol.test', 'displayName' => 'Carol']);

        $this->standing()->refresh([self::ALICE, self::BOB, self::CAROL]);
        $verified = $this->standing()->verification([self::ALICE, self::BOB, self::CAROL]);

        $this->assertSame(Verification::VALID, $verified[self::ALICE]->status);
        $this->assertSame('at://'.self::ISSUER.'/'.self::VERIFY.'/v1', $verified[self::ALICE]->verifications[0]['uri']);
        $this->assertSame(Verification::INVALID, $verified[self::BOB]->status, 'Verified once, the display name since changed.');
        $this->assertSame('displayName', $verified[self::BOB]->verifications[0]['reason']);
        $this->assertSame(Verification::NONE, $verified[self::CAROL]->status, 'Only trusted issuers count.');
        $this->assertTrue($this->standing()->trusted(self::ISSUER));
        $this->assertFalse($this->standing()->trusted(self::STRANGER));
    }

    public function test_a_verification_found_through_constellation_counts_only_once_proven(): void
    {
        config()->set('atp-appview.ingest.collections', [self::PROFILE]);
        config()->set('atp-appview.standing.verification.verifiers', [['class' => RecordVerifier::class, 'collection' => self::VERIFY, 'issuers' => [self::ISSUER]]]);
        $this->account(self::ALICE, 'alice.test', 'Alice');

        $key = Secp256k1Keypair::create();
        $car = $this->signedProof($key, ['$type' => self::VERIFY, 'subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice', 'createdAt' => '2026-10-01T00:00:00Z']);

        // Constellation filters by issuer too, but a stranger's record is never fetched even if it slips through.
        $this->constellation(0, [['did' => self::ISSUER, 'collection' => self::VERIFY, 'rkey' => 'v1'], ['did' => self::STRANGER, 'collection' => self::VERIFY, 'rkey' => 'v2']]);
        Resolver::shouldReceive('resolveDid')->with(self::ISSUER)->andReturn(DidDocument::fromArray([
            'id' => self::ISSUER,
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://issuer-pds.example']],
            'verificationMethod' => [['id' => self::ISSUER.'#atproto', 'type' => 'Multikey', 'controller' => self::ISSUER, 'publicKeyMultibase' => substr($key->did(), strlen('did:key:'))]],
        ]));
        Atp::fake()->stub('com.atproto.sync.getRecord', new Response(new LaravelResponse(new Psr7Response(200, ['Content-Type' => 'application/vnd.ipld.car'], $car))));

        $this->standing()->refresh([self::ALICE]);
        $verified = $this->standing()->verification([self::ALICE])[self::ALICE];
        $this->assertSame(Verification::VALID, $verified->status);
        $this->assertTrue($verified->complete, 'Every trusted candidate was proven.');
        $this->assertCount(1, $verified->verifications);

        // Signed by another key: not proven, so neither valid nor invalid, and tried again soon.
        Atp::fake()->stub('com.atproto.sync.getRecord', new Response(new LaravelResponse(new Psr7Response(200, [], $this->signedProof(Secp256k1Keypair::create(), ['$type' => self::VERIFY, 'subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice'])))));
        $this->travel(7)->hours();
        $this->standing()->refresh([self::ALICE]);

        $verification = $this->standing()->verification([self::ALICE])[self::ALICE];
        $this->assertSame(Verification::NONE, $verification->status);
        $this->assertFalse($verification->complete);
        $this->assertSame('2026-10-21 19:15:00', DB::table('atp_standing')->where('subject', self::ALICE)->where('kind', 'verification')->value('stale_at'));
    }

    public function test_label_verification_counts_checked_labels_from_chosen_labelers(): void
    {
        config()->set('atp-appview.standing.verification.verifiers', [['class' => LabelVerifier::class, 'labelers' => [self::LABELER], 'values' => ['verified']]]);
        $label = fn (string $subject, string $src, bool $checked) => DB::table('atp_labels')->insert(['src' => $src, 'uri' => $subject, 'subject' => $subject, 'val' => 'verified', 'cts' => '2026-10-01T00:00:00.000Z', 'verified' => $checked]);
        $label(self::ALICE, self::LABELER, true);
        $label(self::BOB, self::LABELER, false);
        $label(self::CAROL, self::STRANGER, true);

        $this->standing()->refresh([self::ALICE, self::BOB, self::CAROL]);
        $verified = $this->standing()->verification([self::ALICE, self::BOB, self::CAROL]);

        $this->assertSame(Verification::VALID, $verified[self::ALICE]->status);
        $this->assertSame(Verification::NONE, $verified[self::BOB]->status, 'A label whose signature was not checked does not count.');
        $this->assertSame(Verification::NONE, $verified[self::CAROL]->status);
    }

    public function test_verifiers_combine_under_any_or_all(): void
    {
        $this->account(self::ALICE, 'alice.test', 'Alice');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice']);
        config()->set('atp-appview.standing.verification.verifiers', [
            ['class' => RecordVerifier::class, 'collection' => self::VERIFY, 'issuers' => [self::ISSUER], 'prove' => false],
            ['class' => LabelVerifier::class, 'labelers' => [self::LABELER]],
        ]);

        $this->standing()->refresh([self::ALICE]);
        $verified = $this->standing()->verification([self::ALICE])[self::ALICE];
        $this->assertSame(Verification::VALID, $verified->status);
        $this->assertTrue($verified->complete, 'Every trusted candidate was proven.');
        $this->assertCount(1, $verified->verifications);

        config()->set('atp-appview.standing.verification.policy', 'all');
        $this->standing()->refresh([self::ALICE]);
        $this->assertSame(Verification::INVALID, $this->standing()->verification([self::ALICE])[self::ALICE]->status, 'Verified by one of two, which all does not accept.');
    }

    public function test_scores_weigh_metrics_verification_handles_and_authors(): void
    {
        $this->account(self::ALICE, 'alice.team', 'Alice');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.team', 'displayName' => 'Alice']);
        $thing = $this->index(self::ALICE, self::THING, 't1', ['text' => 'hi']);
        foreach (range(1, 9) as $i) {
            $this->index("did:plc:fan{$i}", self::LIKE, "l{$i}", ['subject' => ['uri' => $thing]]);
        }

        $this->standing()->refresh([self::ALICE, $thing]);

        // 25 verified + 10 x log10(99 + 1) + 99 / 150 + 50 for the handle.
        $alice = 25 + 20 + 0.66 + 50;
        $this->assertEqualsWithDelta($alice, $this->standing()->scores([self::ALICE])[self::ALICE], 0.001);
        // log10(9 + 1) + half of Alice's.
        $this->assertEqualsWithDelta(1 + $alice / 2, $this->standing()->scores([$thing])[$thing], 0.001);
    }

    public function test_profiles_carry_verification_and_stats(): void
    {
        $this->account(self::ALICE, 'alice.test', 'Alice');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice']);
        $this->standing()->refresh([self::ALICE]);

        $full = app(Profiles::class)->for([self::ALICE])[self::ALICE];
        $this->assertSame('valid', $full['verification']['verifiedStatus']);
        $this->assertSame('none', $full['verification']['trustedVerifierStatus']);
        $this->assertSame([['issuer' => self::ISSUER, 'uri' => 'at://'.self::ISSUER.'/'.self::VERIFY.'/v1', 'isValid' => true, 'createdAt' => '2026-10-20T00:00:00Z']], $full['verification']['verifications']);
        $this->assertSame(['follows' => 0, 'followers' => 99, 'following' => 150], $full['stats']);

        $basic = app(Profiles::class)->basic([self::ALICE])[self::ALICE];
        $this->assertSame('valid', $basic['verification']['verifiedStatus']);
        $this->assertArrayNotHasKey('stats', $basic);
    }

    public function test_trusted_issuers_can_come_from_a_class_and_are_cached(): void
    {
        CommunityIssuers::$dids = [self::STRANGER];
        CommunityIssuers::$asked = 0;
        config()->set('atp-appview.standing.verification.verifiers', [['class' => RecordVerifier::class, 'collection' => self::VERIFY, 'issuers' => [self::ISSUER, CommunityIssuers::class], 'prove' => false]]);
        $this->account(self::ALICE, 'alice.test', 'Alice');
        $this->account(self::CAROL, 'carol.test', 'Carol');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice']);
        $this->index(self::STRANGER, self::VERIFY, 'v2', ['subject' => self::CAROL, 'handle' => 'carol.test', 'displayName' => 'Carol']);

        $this->standing()->refresh([self::ALICE, self::CAROL]);
        $verified = $this->standing()->verification([self::ALICE, self::CAROL]);

        $this->assertSame(Verification::VALID, $verified[self::ALICE]->status, 'A DID in config still counts.');
        $this->assertSame(Verification::VALID, $verified[self::CAROL]->status, 'So does one the class gives.');
        $this->assertTrue($this->standing()->trusted(self::STRANGER));
        $this->assertSame([self::ISSUER, self::STRANGER], Standing::verifiers()[0]->issuers(), 'What is not a DID is dropped.');

        $asked = CommunityIssuers::$asked;
        $this->standing()->trusted(self::STRANGER);
        Standing::verifiers()[0]->issuers();
        $this->assertSame($asked, CommunityIssuers::$asked, 'The class is asked once per cache period, not on every read.');

        config()->set('atp-appview.standing.verification.verifiers', [['class' => RecordVerifier::class, 'issuers' => [\stdClass::class], 'prove' => false]]);
        $this->expectException(\InvalidArgumentException::class);
        Standing::verifiers()[0]->issuers();
    }

    public function test_fields_match_exactly_as_bluesky_compares_them(): void
    {
        $this->account(self::ALICE, 'alice.test', 'Alice');
        $this->account(self::BOB, '', 'Bob');
        $this->account(self::CAROL, 'carol.test', 'Carol');
        $this->account(self::DAVE, 'dave.test', 'Café');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice ']);
        $this->index(self::ISSUER, self::VERIFY, 'v2', ['subject' => self::BOB, 'handle' => '', 'displayName' => 'Bob']);
        $this->index(self::ISSUER, self::VERIFY, 'v3', ['subject' => self::CAROL, 'handle' => 'Carol.test', 'displayName' => 'Carol']);
        // The same é, decomposed: e and a combining accent.
        $this->index(self::ISSUER, self::VERIFY, 'v4', ['subject' => self::DAVE, 'handle' => 'dave.test', 'displayName' => "Cafe\u{0301}"]);

        $this->standing()->refresh([self::ALICE, self::BOB, self::CAROL, self::DAVE]);
        $verified = $this->standing()->verification([self::ALICE, self::BOB, self::CAROL, self::DAVE]);

        $this->assertSame(['invalid', 'displayName'], [$verified[self::ALICE]->status, $verified[self::ALICE]->verifications[0]['reason']], 'No trimming.');
        $this->assertSame(['invalid', 'handle'], [$verified[self::BOB]->status, $verified[self::BOB]->verifications[0]['reason']], 'An empty value never matches, even an empty one.');
        $this->assertSame(['invalid', 'handle'], [$verified[self::CAROL]->status, $verified[self::CAROL]->verifications[0]['reason']], 'No case folding.');
        $this->assertSame(['invalid', 'displayName'], [$verified[self::DAVE]->status, $verified[self::DAVE]->verifications[0]['reason']], 'No Unicode normalization.');
    }

    public function test_an_impersonation_label_from_a_named_labeler_outweighs_verification(): void
    {
        config()->set('atp-appview.standing.verification.impersonation', ['labelers' => [self::LABELER], 'values' => ['impersonation']]);
        config()->set('atp-appview.standing.verification.verifiers', [['class' => RecordVerifier::class, 'collection' => self::VERIFY, 'issuers' => [self::ISSUER, self::ALICE], 'prove' => false]]);
        $this->account(self::ALICE, 'alice.test', 'Alice');
        $this->account(self::BOB, 'bob.test', 'Bob');
        $this->index(self::ISSUER, self::VERIFY, 'v1', ['subject' => self::ALICE, 'handle' => 'alice.test', 'displayName' => 'Alice']);
        $this->index(self::ISSUER, self::VERIFY, 'v2', ['subject' => self::BOB, 'handle' => 'bob.test', 'displayName' => 'Bob']);
        $this->standing()->refresh([self::ALICE, self::BOB]);
        $label = fn (string $subject, string $src, bool $checked) => DB::table('atp_labels')->insert(['src' => $src, 'uri' => $subject, 'subject' => $subject, 'val' => 'impersonation', 'cts' => '2026-10-01T00:00:00.000Z', 'verified' => $checked]);

        $label(self::ALICE, self::LABELER, true);
        $label(self::BOB, self::STRANGER, true);
        $label(self::BOB, self::LABELER, false);

        $profiles = app(Profiles::class)->for([self::ALICE, self::BOB]);
        $this->assertSame('invalid', $profiles[self::ALICE]['verification']['verifiedStatus'], 'Applied as read, with no refresh.');
        $this->assertTrue($profiles[self::ALICE]['verification']['verifications'][0]['isValid'], 'Each record still holds on its own.');
        $this->assertSame('invalid', $profiles[self::ALICE]['verification']['trustedVerifierStatus'], 'A trusted verifier labeled an impersonation is invalid too.');
        $this->assertSame('valid', $profiles[self::BOB]['verification']['verifiedStatus'], 'Another labeler, or a label not checked, does not count.');

        config()->set('atp-appview.standing.verification.impersonation.labelers', []);
        $this->assertSame(Verification::VALID, $this->standing()->verification([self::ALICE])[self::ALICE]->status, 'No labelers named, no check.');
    }

    public function test_an_account_without_a_valid_handle_shows_no_verification(): void
    {
        DB::table('atp_identities')->insert(['did' => self::ALICE, 'handle' => null, 'active' => true, 'updated_at' => now()]);
        $this->index(self::ALICE, self::PROFILE, 'self', ['displayName' => 'Alice']);
        Resolver::shouldReceive('resolveDid')->andThrow(new \RuntimeException('offline'));

        $this->assertArrayNotHasKey('verification', app(Profiles::class)->for([self::ALICE])[self::ALICE]);
    }

    public function test_the_command_refreshes_what_has_gone_stale(): void
    {
        $this->standing()->refresh([self::ALICE]);
        $this->constellation(5);

        $this->artisan('appview:standing:refresh', ['--stale' => true])->assertSuccessful();
        $this->assertSame(99, $this->standing()->metricsFor([self::ALICE])[self::ALICE]['followers'], 'Nothing is stale yet.');

        $this->travel(2)->days();
        $this->artisan('appview:standing:refresh', ['--stale' => true])->assertSuccessful();
        $this->assertSame(5, $this->standing()->metricsFor([self::ALICE])[self::ALICE]['followers']);
    }

    public function test_without_the_feature_profiles_are_unchanged_and_refresh_refuses(): void
    {
        config()->set('atp-appview.features', []);
        $this->account(self::ALICE, 'alice.test', 'Alice');

        $this->assertSame([], $this->standing()->metricsFor([]));

        $this->expectException(LogicException::class);
        $this->standing()->refresh([self::ALICE]);
    }

    /**
     * A one-record repo signed with a key, as getRecord serves it: the CAR
     * of the commit, the tree node and the record.
     *
     * @param  array<string, mixed>  $record
     */
    protected function signedProof(Secp256k1Keypair $key, array $record): string
    {
        $recordBytes = CBOR::encode($record);
        $node = CBOR::encode(['l' => null, 'e' => [['p' => 0, 'k' => self::VERIFY.'/v1', 'v' => CID::forDagCborBytes($recordBytes), 't' => null]]]);
        $unsigned = ['did' => self::ISSUER, 'version' => 3, 'data' => CID::forDagCborBytes($node), 'rev' => '3l2aaaaaaaaaa', 'prev' => null];
        $commit = CBOR::encode($unsigned + ['sig' => $key->sign(CBOR::encode($unsigned))]);
        $blocks = [$commit, $node, $recordBytes];
        $cids = array_map(fn (string $block) => CID::forDagCborBytes($block), $blocks);
        $header = CBOR::encode(['version' => 1, 'roots' => [$cids[0]]]);
        $car = self::varint(strlen($header)).$header;

        foreach ($blocks as $i => $block) {
            $car .= self::varint(strlen($cids[$i]->toBinary().$block)).$cids[$i]->toBinary().$block;
        }

        return $car;
    }

    protected static function varint(int $value): string
    {
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $bytes;
    }
}
