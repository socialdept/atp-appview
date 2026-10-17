<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\RecordWriter;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

class RecordIndexerTest extends TestCase
{
    protected const DID = 'did:plc:alice';

    // TIDs, in increasing order.
    protected const OLD = '3l2aaaaaaaaaa';
    protected const NEW = '3l2aaaaaaaaab';
    protected const NEWER = '3l2aaaaaaaaac';

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    public function test_a_create_is_indexed(): void
    {
        $this->index($this->event(RecordAction::Create, self::OLD, ['text' => 'hello']));

        $record = Record::find('at://did:plc:alice/app.example.post/3abc');

        $this->assertSame(self::DID, $record->did);
        $this->assertSame('app.example.post', $record->collection);
        $this->assertSame('3abc', $record->rkey);
        $this->assertSame('bafy-'.self::OLD, $record->cid);
        $this->assertSame(self::OLD, $record->rev);
        $this->assertSame(['text' => 'hello'], $record->record);
        $this->assertNotNull($record->indexed_at);
    }

    public function test_a_newer_update_overwrites_and_an_older_one_is_ignored(): void
    {
        $this->index($this->event(RecordAction::Create, self::NEW, ['text' => 'second']));
        $this->index($this->event(RecordAction::Update, self::OLD, ['text' => 'stale']));

        $this->assertSame(['text' => 'second'], $this->find()->record);

        $this->index($this->event(RecordAction::Update, self::NEWER, ['text' => 'third']));

        $this->assertSame(['text' => 'third'], $this->find()->record);
        $this->assertSame(self::NEWER, $this->find()->rev);
    }

    public function test_redelivering_the_same_batch_changes_nothing(): void
    {
        $event = $this->event(RecordAction::Create, self::OLD, ['text' => 'hello']);

        $this->index($event);
        $this->index($event);

        $this->assertSame(1, Record::count());
        $this->assertSame(['text' => 'hello'], $this->find()->record);
    }

    public function test_a_delete_leaves_a_tombstone_that_queries_skip(): void
    {
        $this->index($this->event(RecordAction::Create, self::OLD, ['text' => 'hello']));
        $this->index($this->event(RecordAction::Delete, self::NEW));

        $this->assertNull($this->find());

        $tombstone = Record::withTrashed()->find('at://did:plc:alice/app.example.post/3abc');
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertNull($tombstone->record);
        $this->assertNull($tombstone->cid);
        $this->assertSame(self::NEW, $tombstone->rev);
    }

    public function test_an_older_create_replayed_after_a_delete_does_not_bring_the_record_back(): void
    {
        $this->index($this->event(RecordAction::Delete, self::NEW));
        $this->index($this->event(RecordAction::Create, self::OLD, ['text' => 'from backfill']));

        $this->assertNull($this->find());
    }

    public function test_a_newer_create_after_a_delete_brings_the_record_back(): void
    {
        $this->index($this->event(RecordAction::Delete, self::OLD));
        $this->index($this->event(RecordAction::Create, self::NEW, ['text' => 'recreated']));

        $this->assertSame(['text' => 'recreated'], $this->find()->record);
        $this->assertNull($this->find()->deleted_at);
    }

    public function test_several_events_for_one_record_in_a_batch_collapse_to_the_last(): void
    {
        $this->index(
            $this->event(RecordAction::Create, self::OLD, ['text' => 'first']),
            $this->event(RecordAction::Update, self::NEW, ['text' => 'second']),
            $this->event(RecordAction::Delete, self::NEWER),
        );

        $this->assertNull($this->find());
        $this->assertSame(self::NEWER, Record::withTrashed()->first()->rev);
    }

    public function test_identities_are_upserted(): void
    {
        $this->index(new IdentityEvent(1, self::DID, 'alice.example', true, 'active'));
        $this->index(new IdentityEvent(2, self::DID, 'alice.new', false, 'deactivated'));

        $identity = Identity::find(self::DID);

        $this->assertSame('alice.new', $identity->handle);
        $this->assertFalse($identity->active);
        $this->assertSame('deactivated', $identity->status);
        $this->assertSame(1, Identity::count());
    }

    public function test_a_batch_larger_than_one_statement_is_written_in_chunks(): void
    {
        $events = [];

        foreach (range(1, RecordWriter::CHUNK * 2 + 37) as $i) {
            $events[] = new RecordEvent($i, self::DID, 'app.example.post', "r{$i}", RecordAction::Create, 'bafy', self::OLD, ['n' => $i], false);
        }

        $this->index(...$events);

        $this->assertSame(RecordWriter::CHUNK * 2 + 37, Record::count());
    }

    public function test_the_model_scopes_and_identity_relation(): void
    {
        $this->index(
            new RecordEvent(1, self::DID, 'app.example.post', 'a', RecordAction::Create, 'bafy', self::OLD, ['n' => 1], true),
            new RecordEvent(2, self::DID, 'app.example.like', 'b', RecordAction::Create, 'bafy', self::OLD, ['n' => 2], true),
            new RecordEvent(3, 'did:plc:bob', 'app.example.post', 'c', RecordAction::Create, 'bafy', self::OLD, ['n' => 3], true),
            new IdentityEvent(4, self::DID, 'alice.example', true, 'active'),
        );

        $this->assertSame(2, Record::collection('app.example.post')->count());
        $this->assertSame(1, Record::collection('app.example.post')->by(self::DID)->count());
        $this->assertSame('alice.example', Record::collection('app.example.like')->first()->identity->handle);
        $this->assertSame(2, Identity::find(self::DID)->records()->count());
    }

    public function test_tap_messages_flow_through_the_consumer_into_the_index(): void
    {
        config()->set('atp-appview.ingest.batch_size', 2);

        $consumer = $this->app->make(TapConsumer::class);
        $consumer->handleMessage(json_encode(['id' => 1, 'type' => 'record', 'record' => [
            'live' => false, 'rev' => self::OLD, 'did' => self::DID, 'collection' => 'app.example.post',
            'rkey' => '3abc', 'action' => 'create', 'cid' => 'bafy', 'record' => ['text' => 'via tap'],
        ]]));
        $consumer->handleMessage(json_encode(['id' => 2, 'type' => 'identity', 'identity' => [
            'did' => self::DID, 'handle' => 'alice.example', 'is_active' => true, 'status' => 'active',
        ]]));

        $this->assertSame(['text' => 'via tap'], $this->find()->record);
        $this->assertSame('alice.example', Identity::find(self::DID)->handle);
    }

    protected function index(RecordEvent|IdentityEvent ...$events): void
    {
        $this->app->make(RecordIndexer::class)->handle(new Batch($events));
    }

    protected function find(): ?Record
    {
        return Record::find('at://did:plc:alice/app.example.post/3abc');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function test_sort_at_uses_the_collections_configured_fields(): void
    {
        config()->set('atp-appview.index.sort_fields', ['*' => ['createdAt'], 'app.example.post' => ['publishedAt', 'createdAt']]);

        $this->index(new RecordEvent(1, self::DID, 'app.example.post', 'self', RecordAction::Create, 'bafy', self::OLD, ['publishedAt' => '2016-05-01T00:00:00Z', 'createdAt' => '2024-01-01T00:00:00Z'], false));
        $this->index(new RecordEvent(2, self::DID, 'app.example.like', 'self', RecordAction::Create, 'bafy', self::OLD, ['publishedAt' => '2016-05-01T00:00:00Z', 'createdAt' => '2024-01-01T00:00:00Z'], false));

        $this->assertSame('2016-05-01', Record::find('at://did:plc:alice/app.example.post/self')->sort_at->format('Y-m-d'));
        $this->assertSame('2024-01-01', Record::find('at://did:plc:alice/app.example.like/self')->sort_at->format('Y-m-d'));
    }

    public function test_nul_characters_are_replaced_in_keys_and_values(): void
    {
        $this->index($this->event(RecordAction::Create, self::OLD, ['url' => "https://example.com/\0x", "k\0" => ['deep' => "a\0b"], 'n' => 1]));

        $this->assertSame(['url' => "https://example.com/\u{FFFD}x", "k\u{FFFD}" => ['deep' => "a\u{FFFD}b"], 'n' => 1], $this->find()->record);
    }

    public function test_resort_recomputes_sort_at_after_the_fields_change(): void
    {
        $this->index(new RecordEvent(1, self::DID, 'app.example.post', 'self', RecordAction::Create, 'bafy', self::OLD, ['publishedAt' => '2016-05-01T00:00:00Z'], false));
        $this->index(new RecordEvent(2, self::DID, 'app.example.post', 'other', RecordAction::Create, 'bafy', self::OLD, ['createdAt' => '2024-01-01T00:00:00Z'], false));
        $this->assertNotSame('2016-05-01', Record::find('at://did:plc:alice/app.example.post/self')->sort_at->format('Y-m-d'));

        config()->set('atp-appview.index.sort_fields', ['app.example.post' => ['publishedAt', 'createdAt']]);

        $this->artisan('appview:resort', ['collection' => ['app.example.post']])
            ->expectsOutputToContain('1 records resorted')
            ->assertSuccessful();

        $this->assertSame('2016-05-01', Record::find('at://did:plc:alice/app.example.post/self')->sort_at->format('Y-m-d'));
        $this->assertSame('2024-01-01', Record::find('at://did:plc:alice/app.example.post/other')->sort_at->format('Y-m-d'));
    }

    protected function event(RecordAction $action, string $rev, ?array $body = null): RecordEvent
    {
        static $id = 0;

        return new RecordEvent(++$id, self::DID, 'app.example.post', '3abc', $action, $action === RecordAction::Delete ? null : 'bafy-'.$rev, $rev, $body, true);
    }
}
