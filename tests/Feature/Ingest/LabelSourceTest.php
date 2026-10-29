<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Cursors;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\Heartbeats;
use SocialDept\AtpAppview\Ingest\Labels\LabelerLookup;
use SocialDept\AtpAppview\Ingest\Labels\LabelSource;
use SocialDept\AtpAppview\Ingest\Labels\LabelVerifier;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Labeler;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpSignals\Labels\LabelStream;
use SocialDept\AtpSignals\Support\WebSocketConnection;

/**
 * Labels from each labeler's own stream in place of Tack: kept only about
 * known accounts, from the labeler that carried them and signed by it, with
 * a cursor per labeler saved with each batch, and streams opened and closed
 * as labelers are followed.
 */
class LabelSourceTest extends TestCase
{
    use SignsLabels;

    protected const MOD = 'did:plc:mod';
    protected const OTHER = 'did:plc:other';
    protected const ALICE = 'did:plc:alice';
    protected const STRANGER = 'did:plc:stranger';

    protected PrivateKey $key;

    protected FakeLookup $lookup;

    protected LabelVerifier $verifier;

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.labels.source', 'streams');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        DB::table('atp_identities')->insert(['did' => self::ALICE, 'handle' => 'alice.test', 'active' => true, 'updated_at' => now()]);

        $this->key = EC::createKey('secp256k1');
        $this->lookup = new FakeLookup();
        $this->lookup->keys[self::MOD] = self::didKey($this->key);
        $this->app->instance(LabelerLookup::class, $this->lookup);
        $this->verifier = new LabelVerifier($this->lookup);
    }

    protected function tearDown(): void
    {
        // Streams opened here leave timers, which React would run at shutdown.
        Loop::stop();

        parent::tearDown();
    }

    protected function primeKey(LabelSource $source): void
    {
        $this->verifier->key(self::MOD);
    }

    protected function source(array $handlers = [], ?\Closure $streams = null): LabelSource
    {
        $source = new LabelSource($handlers ?: [new LabelIndexer()], app(Cursors::class), $this->verifier, streamFactory: $streams);
        $source->refreshKnown();

        return $source;
    }

    /**
     * A label as a labeler sends it, signed with $key.
     *
     * @return array<string, mixed>
     */
    protected function label(string $uri, string $val = 'spam', bool $neg = false, string $src = self::MOD, ?PrivateKey $key = null, string $cts = '2026-10-21T12:00:00.000Z'): array
    {
        $label = ['ver' => 1, 'src' => $src, 'uri' => $uri, 'val' => $val, 'neg' => $neg, 'cts' => $cts];

        return $label + ['sig' => self::signLabel($key ?? $this->key, $label)];
    }

    public function test_a_signed_label_about_a_known_account_is_kept_with_the_labelers_cursor(): void
    {
        $this->source()->handle(self::MOD, [$this->label(self::ALICE), $this->label('at://'.self::ALICE.'/app.example.post/1', 'gore')], 42);

        $this->assertEqualsCanonicalizing(['spam', 'gore'], Label::query()->pluck('val')->all());
        $this->assertTrue(Label::query()->where('val', 'spam')->sole()->verified);
        $this->assertSame(42, app(Cursors::class)->get('labels:'.self::MOD));
    }

    public function test_labels_about_other_accounts_are_skipped_before_their_signatures(): void
    {
        $this->lookup->keys = [];
        $source = $this->source();

        $kept = $source->map(self::MOD, [$this->label(self::STRANGER), $this->label('at://'.self::STRANGER.'/app.example.post/1')]);

        $this->assertSame([], $kept);
        $this->assertSame(0, $this->lookup->calls, 'No key was needed.');
    }

    public function test_a_label_that_does_not_verify_or_names_another_labeler_is_dropped(): void
    {
        $forged = $this->label(self::ALICE, 'forged', key: EC::createKey('secp256k1'));
        $tampered = ['val' => 'tampered'] + $this->label(self::ALICE);
        $unsigned = $this->label(self::ALICE, 'unsigned');
        unset($unsigned['sig']);
        $elsewhere = $this->label(self::ALICE, 'elsewhere', src: self::OTHER);

        $source = $this->source();
        $this->primeKey($source);

        $kept = $source->map(self::MOD, [$forged, $tampered, $unsigned, $elsewhere, $this->label(self::ALICE)]);

        $this->assertSame(['spam'], array_map(fn ($event) => $event->val, $kept));
    }

    public function test_a_rotated_key_is_looked_up_again_once_in_a_while(): void
    {
        $source = $this->source();
        $this->primeKey($source);
        $rotated = EC::createKey('secp256k1');
        $this->lookup->keys[self::MOD] = self::didKey($rotated);
        $this->lookup->calls = 0;

        $this->assertCount(1, $source->map(self::MOD, [$this->label(self::ALICE, key: $rotated)]), 'The new key was fetched.');
        $this->assertSame(1, $this->lookup->calls);
        $this->assertTrue($this->lookup->fresh, 'Past the cache.');

        $this->assertCount(0, $source->map(self::MOD, [$this->label(self::ALICE, key: EC::createKey('secp256k1'))]));
        $this->assertSame(1, $this->lookup->calls, 'A forgery right after does not look it up again.');
    }

    public function test_a_withdrawal_removes_the_label(): void
    {
        $source = $this->source();
        $source->handle(self::MOD, [$this->label(self::ALICE)], 1);
        $source->handle(self::MOD, [$this->label(self::ALICE, neg: true, cts: '2026-10-21T13:00:00.000Z')], 2);

        $this->assertSame(0, Label::query()->count());
        $this->assertSame(2, app(Cursors::class)->get('labels:'.self::MOD));
    }

    public function test_a_failed_batch_leaves_the_cursor_where_it_was(): void
    {
        $failing = new class () implements BatchHandler {
            public function handle(Batch $batch): void
            {
                throw new RuntimeException('database went away');
            }
        };

        $source = $this->source([$failing]);
        app(Cursors::class)->set('labels:'.self::MOD, 7);

        try {
            $source->handle(self::MOD, [$this->label(self::ALICE)], 8);
            $this->fail('Expected the batch to fail.');
        } catch (BatchFailed) {
        }

        $this->assertSame(7, app(Cursors::class)->get('labels:'.self::MOD));
    }

    public function test_streams_open_and_close_as_labelers_are_followed(): void
    {
        $urls = [];
        $closed = [];
        $streams = function (string $endpoint) use (&$urls, &$closed) {
            return new LabelStream($endpoint, connections: function () use (&$urls, &$closed) {
                return new FakeConnection(Loop::get(), $urls, $closed);
            });
        };

        DB::table('atp_labelers')->insert([
            ['did' => self::MOD, 'endpoint' => 'https://mod.example', 'created_at' => now(), 'updated_at' => now()],
            ['did' => self::OTHER, 'endpoint' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(Cursors::class)->set('labels:'.self::MOD, 5);

        $source = $this->source(streams: $streams);
        $source->refresh();

        $this->assertSame([self::MOD], $source->following(), 'A labeler without an endpoint has no stream.');

        $source->withHeartbeat(app(Heartbeats::class))->heartbeat();
        $this->assertSame(['following' => 1], app(Heartbeats::class)->state(LabelSource::HEARTBEAT), 'The heartbeat says how many streams are open.');
        $this->assertSame(['wss://mod.example/xrpc/com.atproto.label.subscribeLabels?cursor=5'], $urls);

        DB::table('atp_labelers')->where('did', self::MOD)->update(['endpoint' => 'https://moved.example']);
        $source->refresh();

        $this->assertSame(1, count($closed), 'The old endpoint was closed.');
        $this->assertSame('wss://moved.example/xrpc/com.atproto.label.subscribeLabels?cursor=5', $urls[1]);

        DB::table('atp_labelers')->where('did', self::MOD)->delete();
        $source->refresh();

        $this->assertSame([], $source->following());
        $this->assertSame(2, count($closed));
    }

    public function test_a_labeler_whose_key_cannot_be_found_is_tried_again_later(): void
    {
        $urls = [];
        $closed = [];
        $streams = function (string $endpoint) use (&$urls, &$closed) {
            return new LabelStream($endpoint, connections: function () use (&$urls, &$closed) {
                return new FakeConnection(Loop::get(), $urls, $closed);
            });
        };
        DB::table('atp_labelers')->insert(['did' => self::OTHER, 'endpoint' => 'https://other.example', 'created_at' => now(), 'updated_at' => now()]);

        $source = $this->source(streams: $streams);
        $source->refresh();
        $this->assertSame([], $source->following());

        $this->lookup->keys[self::OTHER] = self::didKey($this->key);
        $source->refresh();
        $this->assertSame([self::OTHER], $source->following());
    }

    public function test_following_and_unfollowing_without_tack(): void
    {
        $this->lookup->endpoints[self::MOD] = 'https://mod.example';
        $this->lookup->declarations[self::MOD] = ['policies' => ['labelValues' => ['spam']]];

        app(Labels::class)->follow(self::MOD);

        $labeler = Labeler::query()->sole();
        $this->assertSame('https://mod.example', $labeler->endpoint);
        $this->assertSame(['spam'], $labeler->values());

        $this->source()->handle(self::MOD, [$this->label(self::ALICE)], 9);
        app(Labels::class)->unfollow(self::MOD);

        $this->assertSame(0, Labeler::query()->count());
        $this->assertSame(0, Label::query()->count());
        $this->assertNull(app(Cursors::class)->get('labels:'.self::MOD), 'Following again starts from now.');
    }

    public function test_the_sync_rechecks_declarations_every_few_hours(): void
    {
        Queue::fake();
        $this->lookup->endpoints[self::MOD] = 'https://mod.example';
        DB::table('atp_labelers')->insert(['did' => self::MOD, 'endpoint' => 'https://mod.example', 'created_at' => now(), 'updated_at' => now()]);
        $this->lookup->declarations[self::MOD] = ['policies' => ['labelValues' => ['spam', 'scam']]];

        $this->artisan('appview:labels:sync')->expectsOutputToContain('1 labelers rechecked')->assertSuccessful();
        $this->assertSame(['spam', 'scam'], Labeler::query()->sole()->values());

        $this->artisan('appview:labels:sync')->expectsOutputToContain('0 labelers rechecked')->assertSuccessful();
    }
}

class FakeConnection extends WebSocketConnection
{
    /**
     * @param  array<int, string>  $urls
     * @param  array<int, string>  $closed
     */
    public function __construct(LoopInterface $loop, protected array &$urls, protected array &$closed)
    {
        parent::__construct($loop);
    }

    public function connect(string $url, array $subProtocols = []): PromiseInterface
    {
        $this->urls[] = $url;

        return resolve(null);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed[] = $code;
    }
}
