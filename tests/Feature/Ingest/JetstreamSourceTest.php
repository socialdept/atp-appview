<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Cursors;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Ingest\Jetstream\JetstreamSource;
use SocialDept\AtpAppview\Ingest\Jetstream\ResolveIdentity;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Support\JetstreamV2Translator;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Facades\Resolver;

/**
 * Jetstream in place of Tap: records, identities and account status mapped
 * onto the pipeline, companions and network-wide events kept only for repos
 * the index knows, new repos looked up in the queue, and the cursor saved
 * with each batch.
 */
class JetstreamSourceTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const POST = 'com.example.post';
    protected const PROFILE = 'com.example.actor.profile';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.ingest.collections', [self::POST]);
        $app['config']->set('atp-appview.ingest.companions', [self::PROFILE]);
        $app['config']->set('atp-appview.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Queue::fake();
        DB::table('atp_identities')->insert(['did' => self::ALICE, 'handle' => 'alice.test', 'active' => true, 'updated_at' => now()]);
    }

    protected function source(array $handlers = [], ?FailedDeliveries $failed = null): JetstreamSource
    {
        $source = new JetstreamSource($handlers ?: [app(RecordIndexer::class)], app(Cursors::class), 'wss://jetstream.example', failedDeliveries: $failed);
        $source->refreshKnown();

        return $source;
    }

    protected function event(array $payload): SignalEvent
    {
        return JetstreamV2Translator::toSignalEvent($payload + ['time' => '2026-10-21T12:00:00.000000Z']);
    }

    protected function commit(int $seq, string $did, string $collection, string $rkey, string $operation = 'create', ?array $record = ['text' => 'hi']): SignalEvent
    {
        return $this->event(['$type' => 'network.bsky.jetstream.subscribeEvents#commit', 'did' => $did, 'seq' => $seq, 'operation' => $operation, 'collection' => $collection, 'rkey' => $rkey, 'rev' => "3krev{$seq}", 'cid' => $operation === 'delete' ? null : "bafy{$seq}"] + ($operation === 'delete' ? [] : ['record' => ['$type' => $collection] + $record]));
    }

    public function test_commits_are_indexed_and_the_cursor_is_saved_with_the_batch(): void
    {
        $source = $this->source();

        $source->handle([$this->commit(10, self::ALICE, self::POST, 'p1'), $this->commit(11, self::ALICE, self::POST, 'p2')], 11);
        $this->assertSame(['hi', 'hi'], Record::query()->orderBy('rkey')->pluck('record')->map(fn ($r) => $r['text'])->all());
        $this->assertSame(11, app(Cursors::class)->get(JetstreamSource::CURSOR));

        $this->assertSame(
            [\SocialDept\AtpAppview\Ingest\Events\RecordAction::Create, \SocialDept\AtpAppview\Ingest\Events\RecordAction::Update, \SocialDept\AtpAppview\Ingest\Events\RecordAction::Delete],
            array_map(fn ($event) => $event->action, $source->map([$this->commit(1, self::ALICE, self::POST, 'a'), $this->commit(2, self::ALICE, self::POST, 'a', 'update'), $this->commit(3, self::ALICE, self::POST, 'a', 'delete')])),
            'Each operation maps to its own action, which notifiers and record events read.',
        );

        $source->handle([$this->commit(12, self::ALICE, self::POST, 'p1', 'update', ['text' => 'edited']), $this->commit(13, self::ALICE, self::POST, 'p2', 'delete')], 13);
        $this->assertSame('edited', Record::query()->where('rkey', 'p1')->value('record')['text']);
        $this->assertSame(0, Record::query()->where('rkey', 'p2')->count(), 'A delete tombstones the record.');
        $this->assertSame(13, app(Cursors::class)->get(JetstreamSource::CURSOR));
    }

    public function test_companions_identities_and_accounts_count_only_for_repos_the_index_knows(): void
    {
        $mapped = $this->source()->map([
            $this->commit(1, self::BOB, self::PROFILE, 'self', record: ['displayName' => 'Bob']),
            $this->event(['$type' => 'network.bsky.jetstream.subscribeEvents#identity', 'did' => self::BOB, 'seq' => 2, 'identity' => ['did' => self::BOB, 'handle' => 'bob.test', 'seq' => 9]]),
            $this->event(['$type' => 'network.bsky.jetstream.subscribeEvents#account', 'did' => self::BOB, 'seq' => 3, 'account' => ['did' => self::BOB, 'active' => false, 'status' => 'takendown', 'seq' => 9]]),
            $this->commit(4, self::ALICE, self::PROFILE, 'self', record: ['displayName' => 'Alice']),
        ]);

        $this->assertSame([4], array_map(fn ($event) => $event->id, $mapped), 'Only Alice is known, so only her profile counts.');
        Queue::assertNothingPushed();
    }

    public function test_a_record_in_the_apps_own_collection_adds_its_repo_and_looks_up_its_handle(): void
    {
        $source = $this->source();

        $source->handle([$this->commit(20, self::BOB, self::POST, 'p1')], 20);
        $mapped = $source->map([$this->commit(21, self::BOB, self::PROFILE, 'self', record: ['displayName' => 'Bob'])]);

        $this->assertSame(1, Record::query()->where('did', self::BOB)->count());
        Queue::assertPushed(ResolveIdentity::class, fn (ResolveIdentity $job) => $job->dids === [self::BOB]);
        $this->assertCount(1, $mapped, 'Known now, so its companion records count too.');
    }

    public function test_identity_and_account_events_keep_the_half_they_do_not_carry(): void
    {
        $source = $this->source();
        $source->handle([$this->commit(30, self::ALICE, self::POST, 'p1')], 30);

        $source->handle([$this->event(['$type' => 'network.bsky.jetstream.subscribeEvents#identity', 'did' => self::ALICE, 'seq' => 31, 'identity' => ['did' => self::ALICE, 'handle' => 'alice.example', 'seq' => 9]])], 31);
        $this->assertSame(['alice.example', 1], [DB::table('atp_identities')->where('did', self::ALICE)->value('handle'), (int) DB::table('atp_identities')->where('did', self::ALICE)->value('active')]);

        $source->handle([$this->event(['$type' => 'network.bsky.jetstream.subscribeEvents#account', 'did' => self::ALICE, 'seq' => 32, 'account' => ['did' => self::ALICE, 'active' => false, 'status' => 'takendown', 'seq' => 9]])], 32);
        $row = DB::table('atp_identities')->where('did', self::ALICE)->first();
        $this->assertSame(['alice.example', 0, 'takendown'], [$row->handle, (int) $row->active, $row->status], 'The handle stays when the account goes.');
        $this->assertSame(0, Record::query()->where('did', self::ALICE)->count(), 'A taken down account\'s records are hidden.');

        $source->handle([$this->event(['$type' => 'network.bsky.jetstream.subscribeEvents#identity', 'did' => self::ALICE, 'seq' => 33, 'identity' => ['did' => self::ALICE, 'handle' => 'alice.again', 'seq' => 9]])], 33);
        $row = DB::table('atp_identities')->where('did', self::ALICE)->first();
        $this->assertSame(['alice.again', 0, 'takendown'], [$row->handle, (int) $row->active, $row->status], 'A handle change does not bring a taken down account back.');
    }

    public function test_a_batch_that_keeps_failing_leaves_the_cursor_where_it_was(): void
    {
        $source = $this->source([new FailsOnText('boom')]);
        app(Cursors::class)->set(JetstreamSource::CURSOR, 5);

        try {
            $source->handle([$this->commit(40, self::ALICE, self::POST, 'p1', record: ['text' => 'boom'])], 40);
            $this->fail('Expected the batch to fail.');
        } catch (BatchFailed) {
            $this->assertSame(5, app(Cursors::class)->get(JetstreamSource::CURSOR), 'So the stream delivers it again.');
        }
    }

    public function test_one_failing_record_is_parked_and_the_rest_go_through(): void
    {
        $source = $this->source([new FailsOnText('boom'), app(RecordIndexer::class)], app(FailedDeliveries::class));

        $source->handle([$this->commit(50, self::ALICE, self::POST, 'ok'), $this->commit(51, self::ALICE, self::POST, 'bad', record: ['text' => 'boom'])], 51);

        $this->assertSame(['ok'], Record::query()->pluck('rkey')->all());
        $this->assertSame(1, DB::table('atp_failed_deliveries')->count());
        $this->assertSame(51, app(Cursors::class)->get(JetstreamSource::CURSOR));
    }

    public function test_new_repos_are_kept_with_a_handle_only_when_it_resolves_back(): void
    {
        Resolver::shouldReceive('resolveDid')->with(self::BOB)->andReturn(DidDocument::fromArray(['id' => self::BOB, 'alsoKnownAs' => ['at://bob.test'], 'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example/']]]));
        Resolver::shouldReceive('handleToDid')->with('bob.test')->andReturn(self::BOB);
        Resolver::shouldReceive('resolveDid')->with('did:plc:carol')->andReturn(DidDocument::fromArray(['id' => 'did:plc:carol', 'alsoKnownAs' => ['at://carol.test']]));
        Resolver::shouldReceive('handleToDid')->with('carol.test')->andReturn('did:plc:someoneelse');
        Resolver::shouldReceive('resolveDid')->with('did:plc:gone')->andThrow(new RuntimeException('no document'));

        (new ResolveIdentity([self::BOB, 'did:plc:carol', 'did:plc:gone']))->handle();

        $rows = DB::table('atp_identities')->whereIn('did', [self::BOB, 'did:plc:carol', 'did:plc:gone'])->get()->keyBy('did');
        $this->assertSame(['bob.test', 'https://pds.example'], [$rows[self::BOB]->handle, $rows[self::BOB]->pds]);
        $this->assertNull($rows['did:plc:carol']->handle, 'A handle that points elsewhere is not theirs.');
        $this->assertNull($rows['did:plc:gone']->handle, 'Kept, so its records still show.');
        $this->assertNotNull($rows[self::BOB]->created_at);
        $this->assertNotNull($rows['did:plc:gone']->created_at);
    }

    public function test_the_command_is_registered_and_from_live_forgets_the_cursor(): void
    {
        $this->assertArrayHasKey('appview:jetstream', Artisan::all());
        $this->assertSame('wss://jetstream.us-east.bsky.network', config('atp-appview.ingest.jetstream.url'));
        $this->assertSame(['com.example.post', 'com.example.actor.profile'], (fn () => $this->collections)->call(app(JetstreamSource::class)));
    }
}

class FailsOnText implements BatchHandler
{
    public function __construct(protected string $text)
    {
    }

    public function handle(Batch $batch): void
    {
        foreach ($batch->records() as $record) {
            if (($record->record['text'] ?? null) === $this->text) {
                throw new RuntimeException('cannot index this one');
            }
        }
    }
}
