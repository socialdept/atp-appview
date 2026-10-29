<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Index\Events\RecordIndexed;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\Retention;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class RetentionTest extends TestCase
{
    protected const POSTS = 'com.example.post';
    protected const LIKES = 'com.example.like';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-appview.features', [Features::notifications()]);
        $app['config']->set('atp-appview.fields', [self::LIKES => ['subject.uri' => 'uri']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function record(string $collection, string $rkey, array $state = []): void
    {
        DB::table('atp_records')->insert([
            'uri' => "at://did:plc:alice/{$collection}/{$rkey}",
            'did' => 'did:plc:alice',
            'collection' => $collection,
            'rkey' => $rkey,
            'cid' => 'cid-'.$rkey,
            'record' => '{}',
            'rev' => '3l2aaaaaaaaaa',
            'indexed_at' => now('UTC'),
            'sort_at' => now('UTC'),
            ...$state,
        ]);
    }

    protected function tombstone(string $collection, string $rkey, int $daysAgo): void
    {
        $this->record($collection, $rkey, ['cid' => null, 'record' => null, 'deleted_at' => Carbon::now('UTC')->subDays($daysAgo)]);
    }

    /**
     * @return array<int, string>
     */
    protected function rkeys(): array
    {
        return DB::table('atp_records')->orderBy('collection')->orderBy('rkey')->pluck('rkey')->all();
    }

    public function test_nothing_is_pruned_until_configured(): void
    {
        $this->tombstone(self::POSTS, 'old', 900);
        $this->record(self::POSTS, 'live', ['indexed_at' => now('UTC')->subYears(3), 'sort_at' => now('UTC')->subYears(3)]);

        $this->artisan('appview:prune')->expectsOutputToContain('Deleted 0 tombstones and 0 expired records, hollowed 0 cold records')->assertSuccessful();

        $this->assertSame(['live', 'old'], $this->rkeys());
    }

    public function test_tombstones_older_than_their_collections_window_are_deleted(): void
    {
        config(['atp-appview.retention.tombstones' => ['*' => 90, self::LIKES => 7, 'com.example.kept' => null]]);

        $this->tombstone(self::POSTS, 'a-old', 91);
        $this->tombstone(self::POSTS, 'b-recent', 30);
        $this->record(self::POSTS, 'c-live', ['indexed_at' => now('UTC')->subYears(3), 'sort_at' => now('UTC')->subYears(3)]);
        $this->tombstone(self::LIKES, 'd-old', 8);
        $this->tombstone(self::LIKES, 'e-recent', 6);
        $this->tombstone('com.example.kept', 'f-kept', 900);

        $this->artisan('appview:prune')
            ->expectsOutputToContain('Deleted 2 tombstones and 0 expired records, hollowed 0 cold records')
            ->assertSuccessful();

        $this->assertSame(['f-kept', 'e-recent', 'b-recent', 'c-live'], $this->rkeys(), 'Live records, recent tombstones and exempt collections stay.');
    }

    public function test_a_dry_run_counts_without_deleting(): void
    {
        config(['atp-appview.retention.tombstones' => ['*' => 30]]);
        $this->tombstone(self::POSTS, 'a', 31);
        $this->tombstone(self::POSTS, 'b', 31);
        $this->tombstone(self::LIKES, 'c', 31);

        $this->artisan('appview:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete 3 tombstones and 0 expired records, would hollow 0 cold records')
            ->assertSuccessful();

        $this->assertCount(3, $this->rkeys());
    }

    public function test_it_works_in_chunks_and_stops_at_the_deadline(): void
    {
        config(['atp-appview.retention.tombstones' => ['*' => 30]]);

        foreach (range(1, 5) as $i) {
            $this->tombstone(self::POSTS, "t{$i}", 31);
        }

        $retention = new Retention();

        $this->assertSame(['counts' => [], 'done' => false], $retention->compactTombstones(2, microtime(true) - 1));
        $this->assertCount(5, $this->rkeys());

        $this->assertSame(['counts' => [self::POSTS => 5], 'done' => true], $retention->compactTombstones(2, microtime(true) + 60));
        $this->assertSame([], $this->rkeys());
    }

    public function test_it_lists_every_collection_the_index_holds(): void
    {
        $this->record(self::POSTS, 'a');
        $this->record(self::POSTS, 'b');
        $this->record(self::LIKES, 'c');
        $this->tombstone('com.example.zzz', 'd', 1);

        $this->assertSame([self::LIKES, self::POSTS, 'com.example.zzz'], (new Retention())->collections());
    }

    public function test_what_references_a_pruned_record_goes_with_it(): void
    {
        config(['atp-appview.retention.tombstones' => ['*' => 30]]);
        $this->tombstone(self::LIKES, 'gone', 31);
        $this->record(self::LIKES, 'kept');
        $ids = DB::table('atp_records')->pluck('id', 'rkey')->map(fn ($id) => (int) $id);

        // A value and a notification left behind, as an account deletion
        // leaves notifications, and a value one would have counted.
        $values = app(RecordValues::class);
        $key = $values->keyId($values->field(self::LIKES, 'subject.uri'));

        foreach ($ids as $rkey => $id) {
            DB::table('atp_record_values')->insert(['key_id' => $key, 'record_id' => $id, 'value_hash' => 42, 'sort_at' => now('UTC'), 'hidden' => false]);
            DB::table('atp_notifications')->insert([
                'recipient_did' => 'did:plc:bob', 'reason' => 'like', 'subject' => 'at://did:plc:bob/com.example.post/p', 'group_key' => 'g',
                'actor_did' => 'did:plc:alice', 'actor_hash' => 1, 'source_uri' => "at://did:plc:alice/".self::LIKES."/{$rkey}", 'indexed_at' => now('UTC'), 'origin' => 'reason',
            ]);
        }

        DB::table('atp_value_counts')->insert(['key_id' => $key, 'value_hash' => 42, 'total' => 2]);

        $this->artisan('appview:prune')->assertSuccessful();

        $this->assertSame([$ids['kept']], DB::table('atp_record_values')->pluck('record_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['at://did:plc:alice/'.self::LIKES.'/kept'], DB::table('atp_notifications')->pluck('source_uri')->all());
        $this->assertSame(1, (int) DB::table('atp_value_counts')->where('key_id', $key)->value('total'), 'The count moves with the value.');
    }

    public function test_it_prunes_the_index_connection(): void
    {
        config([
            'database.connections.index' => DB::connection()->getConfig(),
            'atp-appview.index.connection' => 'index',
            'atp-appview.retention.tombstones' => ['*' => 30],
        ]);

        // A database of its own on SQLite in memory, the same one elsewhere.
        $this->artisan('migrate', ['--database' => 'index'])->run();
        DB::connection('index')->table('atp_records')->insert([
            'uri' => 'at://did:plc:alice/com.example.post/old', 'did' => 'did:plc:alice', 'collection' => self::POSTS, 'rkey' => 'old',
            'rev' => '3l2aaaaaaaaaa', 'indexed_at' => now('UTC'), 'sort_at' => now('UTC'), 'deleted_at' => now('UTC')->subDays(31),
        ]);

        $this->artisan('appview:prune')->expectsOutputToContain('Deleted 1 tombstones and 0 expired records, hollowed 0 cold records')->assertSuccessful();
        $this->assertSame(0, DB::connection('index')->table('atp_records')->count());
    }

    public function test_records_past_their_collections_max_age_are_deleted_with_what_references_them(): void
    {
        // '*' is never a default for age: posts are kept however old.
        config(['atp-appview.retention.max_age' => [self::LIKES => 30, '*' => 1]]);
        $old = now('UTC')->subDays(31);

        $this->record(self::LIKES, 'a-old', ['sort_at' => $old, 'indexed_at' => now('UTC')]);
        $this->record(self::LIKES, 'b-recent', ['sort_at' => now('UTC')->subDays(29)]);
        $this->record(self::LIKES, 'c-old-tombstone', ['sort_at' => $old, 'cid' => null, 'record' => null, 'deleted_at' => now('UTC')]);
        $this->record(self::LIKES, 'd-old-hidden', ['sort_at' => $old, 'hidden_at' => now('UTC')]);
        $this->record(self::POSTS, 'e-old-post', ['sort_at' => now('UTC')->subYears(2)]);

        $id = (int) DB::table('atp_records')->where('rkey', 'a-old')->value('id');
        $values = app(RecordValues::class);
        $key = $values->keyId($values->field(self::LIKES, 'subject.uri'));
        DB::table('atp_record_values')->insert(['key_id' => $key, 'record_id' => $id, 'value_hash' => 7, 'sort_at' => $old, 'hidden' => false]);
        DB::table('atp_value_counts')->insert(['key_id' => $key, 'value_hash' => 7, 'total' => 1]);

        $this->artisan('appview:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete 0 tombstones and 3 expired records, would hollow 0 cold records')
            ->assertSuccessful();
        $this->assertCount(5, $this->rkeys());

        $this->artisan('appview:prune')
            ->expectsOutputToContain('Deleted 0 tombstones and 3 expired records, hollowed 0 cold records')
            ->assertSuccessful();

        $this->assertSame(['b-recent', 'e-old-post'], $this->rkeys());
        $this->assertNull(Retention::maxAgeDays(self::POSTS));
        $this->assertFalse(Retention::outlived(self::POSTS, ['createdAt' => '2001-01-01T00:00:00Z'], 'x', now('UTC')), 'Ingest does not age posts either.');
        $this->assertSame(0, DB::table('atp_record_values')->count());
        $this->assertSame(0, (int) DB::table('atp_value_counts')->where('key_id', $key)->value('total'));
    }

    public function test_ingest_skips_records_already_past_max_age_but_not_deletes(): void
    {
        config(['atp-appview.retention.max_age' => [self::LIKES => 30]]);
        Event::fake([RecordIndexed::class]);

        $event = fn (string $rkey, string $createdAt, RecordAction $action = RecordAction::Create, bool $live = true) => new RecordEvent(
            0,
            'did:plc:alice',
            self::LIKES,
            $rkey,
            $action,
            $action === RecordAction::Delete ? null : "cid-{$rkey}",
            '3l2aaaaaaaaab',
            $action === RecordAction::Delete ? null : ['createdAt' => $createdAt],
            $live,
        );

        app(RecordIndexer::class)->handle(new Batch([
            $event('old', now('UTC')->subDays(31)->toIso8601ZuluString()),
            $event('recent', now('UTC')->subDays(29)->toIso8601ZuluString()),
            $event('backfilled', now('UTC')->subYear()->toIso8601ZuluString(), live: false),
            $event('gone', '', RecordAction::Delete),
        ]));

        $this->assertSame(['gone', 'recent'], $this->rkeys(), 'A delete still leaves a tombstone.');
        Event::assertDispatchedTimes(RecordIndexed::class, 1);
        $this->assertTrue(Retention::outlived(self::LIKES, ['createdAt' => now('UTC')->subDays(31)->toIso8601ZuluString()], 'x', now('UTC')));
        $this->assertFalse(Retention::outlived(self::POSTS, ['createdAt' => '2001-01-01T00:00:00Z'], 'x', now('UTC')), 'Only collections named are aged.');
    }
}
