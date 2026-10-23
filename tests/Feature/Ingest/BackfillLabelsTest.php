<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Labels\BackfillLabels;
use SocialDept\AtpAppview\Ingest\Labels\LabelerLookup;
use SocialDept\AtpAppview\Ingest\Labels\LabelQuery;
use SocialDept\AtpAppview\Ingest\Labels\LabelVerifier;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Labels\SubjectSync;
use SocialDept\AtpAppview\Models\Label;

/**
 * Labels a labeler already had, fetched with queryLabels in place of Tack's
 * backfill: account labels by DID and profile, record labels by wildcard
 * where the labeler takes it and by exact URI where it does not, the newest
 * per value, each checked against the labeler's key.
 */
class BackfillLabelsTest extends TestCase
{
    use SignsLabels;

    protected const MOD = 'did:plc:mod';
    protected const OTHER = 'did:plc:other';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const POST = 'app.example.post';

    protected PrivateKey $key;

    protected FakeLookup $lookup;

    /** @var array<int, array<int, string>> The uriPatterns of each request */
    protected array $asked = [];

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.labels.source', 'streams');
        $app['config']->set('atp-appview.labels.streams.query_rate', 1000);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Http::preventStrayRequests();

        foreach ([self::ALICE, self::BOB] as $did) {
            DB::table('atp_identities')->insert(['did' => $did, 'active' => true, 'updated_at' => now()]);
        }

        (new RecordIndexer(app('db')))->handle(new Batch([new RecordEvent(1, self::ALICE, self::POST, '1', RecordAction::Create, 'bafy1', '3l2a', ['text' => 'hi'], true)]));
        DB::table('atp_labelers')->insert(['did' => self::MOD, 'endpoint' => 'https://mod.example', 'created_at' => now(), 'updated_at' => now()]);

        $this->key = EC::createKey('secp256k1');
        $this->lookup = new FakeLookup();
        $this->lookup->keys[self::MOD] = self::didKey($this->key);
        $this->app->instance(LabelerLookup::class, $this->lookup);
        $this->app->instance(LabelVerifier::class, new LabelVerifier($this->lookup));
    }

    protected function postUri(string $rkey, string $did = self::ALICE): string
    {
        return "at://{$did}/".self::POST."/{$rkey}";
    }

    /**
     * A label as queryLabels returns it, in JSON, sig as {"$bytes": base64}.
     *
     * @return array<string, mixed>
     */
    protected function label(string $uri, string $val = 'spam', bool $neg = false, string $cts = '2026-10-01T00:00:00.000Z', string $src = self::MOD, ?PrivateKey $key = null): array
    {
        $label = ['ver' => 1, 'src' => $src, 'uri' => $uri, 'val' => $val, 'neg' => $neg, 'cts' => $cts];

        return $label + ['sig' => ['$bytes' => base64_encode(self::signLabel($key ?? $this->key, $label))]];
    }

    /**
     * Answer queryLabels from a callback given the request's patterns and cursor.
     *
     * @param  callable(array<int, string>, ?string): mixed  $answer  An array of labels, [labels, cursor], or a response
     */
    protected function labeler(callable $answer): void
    {
        Http::fake(['mod.example/*' => function (Request $request) use ($answer) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $flat);
            preg_match_all('/uriPatterns=([^&]*)/', $request->url(), $matches);
            $patterns = array_map('rawurldecode', $matches[1]);
            $this->asked[] = $patterns;

            $result = $answer($patterns, $flat['cursor'] ?? null);

            if ($result instanceof \GuzzleHttp\Promise\PromiseInterface) {
                return $result;
            }

            [$labels, $cursor] = array_is_list($result) && isset($result[1]) && is_string($result[1]) ? $result : [$result, null];

            return Http::response(['labels' => $labels] + ($cursor === null ? [] : ['cursor' => $cursor]));
        }]);
    }

    protected function backfill(BackfillLabels $job): void
    {
        $job->handle(app(LabelQuery::class), app(LabelVerifier::class));
    }

    public function test_account_and_record_labels_come_by_wildcard_where_the_labeler_takes_it(): void
    {
        $this->labeler(fn (array $patterns) => match (true) {
            in_array(self::ALICE, $patterns, true) => [$this->label(self::ALICE), $this->label('at://'.self::ALICE.'/app.bsky.actor.profile/self', 'impersonation')],
            in_array('at://'.self::ALICE.'/*', $patterns, true) => [$this->label($this->postUri('1'), 'gore')],
            default => [],
        });

        $this->backfill(new BackfillLabels(self::MOD, [self::ALICE, self::BOB]));

        $this->assertEqualsCanonicalizing(['spam', 'impersonation', 'gore'], Label::query()->pluck('val')->all());
        $this->assertSame(
            [[self::ALICE, 'at://'.self::ALICE.'/app.bsky.actor.profile/self', self::BOB, 'at://'.self::BOB.'/app.bsky.actor.profile/self'], ['at://'.self::ALICE.'/*', 'at://'.self::BOB.'/*']],
            $this->asked,
            'Two requests for two accounts, not two each.',
        );
        $this->assertSame(1, (int) DB::table('atp_labelers')->where('did', self::MOD)->value('wildcards'));
    }

    public function test_a_labeler_that_refuses_wildcards_is_asked_for_each_record(): void
    {
        $this->labeler(fn (array $patterns) => match (true) {
            str_ends_with($patterns[0], '/*') => Http::response(['error' => 'InvalidRequest', 'message' => 'wildcards are not supported'], 400),
            in_array($this->postUri('1'), $patterns, true) => [$this->label($this->postUri('1'), 'gore')],
            default => [],
        });

        $this->backfill(new BackfillLabels(self::MOD, [self::ALICE]));

        $this->assertSame(['gore'], Label::query()->pluck('val')->all());
        $this->assertSame(0, (int) DB::table('atp_labelers')->where('did', self::MOD)->value('wildcards'));

        $this->asked = [];
        $this->backfill(new BackfillLabels(self::MOD, [self::BOB]));
        $this->assertCount(1, $this->asked, 'Once known, wildcards are not tried again.');
    }

    public function test_every_page_is_read_and_the_newest_label_decides(): void
    {
        // Ozone pages in no order: the withdrawal comes first, the older label after.
        $this->labeler(fn (array $patterns, ?string $cursor) => match (true) {
            str_ends_with($patterns[0], '/*') => [],
            $cursor === null => [[$this->label(self::ALICE, 'spam', neg: true, cts: '2026-10-02T00:00:00.000Z')], 'page2'],
            $cursor === 'page2' => [[$this->label(self::ALICE, 'spam'), $this->label(self::ALICE, 'rude')], 'page2'],
        });

        $this->backfill(new BackfillLabels(self::MOD, [self::ALICE]));

        $this->assertSame(['rude'], Label::query()->pluck('val')->all());
        $this->assertCount(3, $this->asked, 'Two pages, then the wildcard query.');
    }

    public function test_forged_labels_and_labels_about_others_are_dropped(): void
    {
        $this->labeler(fn (array $patterns) => str_ends_with($patterns[0], '/*') ? [] : [
            $this->label(self::ALICE, 'forged', key: EC::createKey('secp256k1')),
            $this->label(self::ALICE, 'elsewhere', src: self::OTHER),
            $this->label('did:plc:stranger', 'unasked'),
            $this->label(self::ALICE, 'spam'),
        ]);

        $this->backfill(new BackfillLabels(self::MOD, [self::ALICE]));

        $this->assertSame(['spam'], Label::query()->pluck('val')->all());
    }

    public function test_late_records_are_asked_only_where_wildcards_are_not_known_to_work(): void
    {
        $this->labeler(fn (array $patterns) => [$this->label($patterns[0], 'gore')]);

        $this->backfill(new BackfillLabels(self::MOD, [], [$this->postUri('1')]));
        $this->assertSame([[$this->postUri('1')]], $this->asked);

        DB::table('atp_labelers')->update(['wildcards' => true]);
        $this->asked = [];
        $this->backfill(new BackfillLabels(self::MOD, [], [$this->postUri('2')]));
        $this->assertSame([], $this->asked, 'The wildcard query already covered it.');

        Queue::fake();
        DB::table('atp_labelers')->insert(['did' => self::OTHER, 'endpoint' => 'https://other.example', 'wildcards' => false, 'created_at' => now(), 'updated_at' => now()]);
        BackfillLabels::records([$this->postUri('3')]);
        Queue::assertPushed(BackfillLabels::class, 1);
        Queue::assertPushed(BackfillLabels::class, fn (BackfillLabels $job) => $job->labeler === self::OTHER && $job->uris === [$this->postUri('3')]);
    }

    public function test_an_unfollowed_labeler_is_not_asked(): void
    {
        $this->labeler(fn () => []);
        DB::table('atp_labelers')->delete();

        $this->backfill(new BackfillLabels(self::MOD, [self::ALICE]));

        $this->assertSame([], $this->asked);
    }

    public function test_requests_to_one_labeler_are_paced(): void
    {
        $this->labeler(fn () => []);
        $query = new LabelQuery(perSecond: 1);

        $started = microtime(true);
        $query->labels('https://mod.example', self::MOD, [self::ALICE]);
        $query->labels('https://mod.example', self::MOD, [self::BOB]);

        $this->assertGreaterThan(0.5, microtime(true) - $started, 'The second request waited its turn.');
    }

    public function test_the_sync_queues_new_accounts_for_every_labeler(): void
    {
        Queue::fake();
        DB::table('atp_labelers')->insert(['did' => self::OTHER, 'endpoint' => 'https://other.example', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('atp_identities')->where('did', self::BOB)->update(['labels_synced_at' => now()]);

        $result = app(SubjectSync::class)->run();

        $this->assertSame(1, $result['accounts']);
        Queue::assertPushed(BackfillLabels::class, 2);
        Queue::assertPushed(BackfillLabels::class, fn (BackfillLabels $job) => $job->labeler === self::OTHER && $job->dids === [self::ALICE]);
        $this->assertNotNull(DB::table('atp_identities')->where('did', self::ALICE)->value('labels_synced_at'));
    }

    public function test_following_a_labeler_queues_the_accounts_already_synced(): void
    {
        Queue::fake();
        DB::table('atp_identities')->where('did', self::BOB)->update(['labels_synced_at' => now()]);
        $this->lookup->endpoints[self::OTHER] = 'https://other.example';
        $this->lookup->keys[self::OTHER] = self::didKey($this->key);

        app(Labels::class)->follow(self::OTHER);

        Queue::assertPushed(BackfillLabels::class, 1);
        Queue::assertPushed(BackfillLabels::class, fn (BackfillLabels $job) => $job->labeler === self::OTHER && $job->dids === [self::BOB]);
    }
}
