<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Closure;
use Illuminate\Support\Carbon;
use LogicException;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Ingest\Contracts\IngestRepair;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Ingest\Pipes\RunRepairs;
use SocialDept\AtpAppview\Ingest\Rejections;
use SocialDept\AtpAppview\Ingest\Repairs;
use SocialDept\AtpAppview\Models\FailedDelivery;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Testing\FakeResponse;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Facades\Resolver;

class FailedDeliveriesTest extends TestCase
{
    /** @var array<int, int> */
    protected array $acked = [];

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.ingest.handlers', [RecordIndexer::class, Picky::class]);
        $app['config']->set('atp-appview.ingest.password', 'secret');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Picky::$refuse = ['bad'];
        Picky::$down = false;
        Picky::$isoDates = false;
        Spammy::$seen = [];
        Spammy::$dates = [];
    }

    protected function event(int $id, string $rkey, RecordAction $action = RecordAction::Create, array $record = []): RecordEvent
    {
        return new RecordEvent($id, 'did:plc:alice', 'app.example.post', $rkey, $action, $action === RecordAction::Delete ? null : "bafy-{$rkey}", '3l2aaaaaaaaa'.$id, $action === RecordAction::Delete ? null : ['text' => $rkey, ...$record], true);
    }

    protected function buffer(): IngestBuffer
    {
        return new IngestBuffer(
            [app(RecordIndexer::class), new Picky()],
            function (int $id) {
                $this->acked[] = $id;
            },
            maxAttempts: 2,
            sleep: fn () => null,
            failedDeliveries: app(FailedDeliveries::class),
            pipes: [],
        );
    }

    /**
     * @param  array<int, RecordEvent>  $events
     */
    protected function deliver(array $events): void
    {
        $buffer = $this->buffer();

        foreach ($events as $event) {
            $buffer->push($event->id, $event);
        }

        $buffer->flush();
    }

    public function test_a_record_that_keeps_failing_is_parked_and_the_rest_of_its_batch_goes_through(): void
    {
        $this->deliver([$this->event(1, 'a'), $this->event(2, 'b'), $this->event(3, 'bad'), $this->event(4, 'c'), $this->event(5, 'd')]);

        $this->assertSame([1, 2, 3, 4, 5], $this->acked, 'The whole batch is acknowledged.');
        $this->assertSame(['a', 'b', 'c', 'd'], Record::query()->orderBy('rkey')->pluck('rkey')->all());

        $delivery = FailedDelivery::query()->sole();
        $this->assertSame('at://did:plc:alice/app.example.post/bad', $delivery->uri);
        $this->assertSame(RuntimeException::class, $delivery->error_class);
        $this->assertSame('Picky refuses bad', $delivery->error);
        $this->assertSame('bad', $delivery->payload['rkey']);
    }

    public function test_when_everything_fails_the_batch_goes_back_to_tap_until_it_has_failed_in_several_deliveries(): void
    {
        config(['atp-appview.failed_deliveries.max_deliveries' => 3]);
        Picky::$down = true;
        $events = [$this->event(1, 'a'), $this->event(2, 'b')];

        foreach ([1, 2] as $delivery) {
            try {
                $this->deliver($events);
                $this->fail('Expected the batch to go back to Tap.');
            } catch (BatchFailed) {
            }
        }

        $this->assertSame([], $this->acked);
        $this->assertSame(0, FailedDelivery::query()->count());

        // The third delivery fails too: now both are parked and acknowledged.
        $this->deliver($events);
        $this->assertSame([1, 2], $this->acked);
        $this->assertSame(2, FailedDelivery::query()->count());
    }

    public function test_a_poison_event_delivered_by_webhook_is_parked_and_the_request_succeeds(): void
    {
        $this->postJson('/atp-appview/tap', ['events' => [
            ['id' => 1, 'type' => 'record', 'record' => ['live' => true, 'rev' => '3l2aaaaaaaaa1', 'did' => 'did:plc:alice', 'collection' => 'app.example.post', 'rkey' => 'a', 'action' => 'create', 'cid' => 'bafy-a', 'record' => ['text' => 'a']]],
            ['id' => 2, 'type' => 'record', 'record' => ['live' => true, 'rev' => '3l2aaaaaaaaa2', 'did' => 'did:plc:alice', 'collection' => 'app.example.post', 'rkey' => 'bad', 'action' => 'create', 'cid' => 'bafy-bad', 'record' => ['text' => 'bad']]],
        ]], ['Authorization' => 'Basic '.base64_encode('admin:secret')])->assertOk();

        $this->assertSame(['a'], Record::query()->pluck('rkey')->all());
        $this->assertSame('bad', FailedDelivery::query()->sole()->payload['rkey']);
    }

    public function test_a_failed_delivery_is_retried_once_the_cause_is_fixed(): void
    {
        $this->deliver([$this->event(1, 'a'), $this->event(2, 'bad')]);
        $delivery = FailedDelivery::query()->sole();

        // Still broken: kept, with the attempt noted.
        $this->assertFalse(app(FailedDeliveries::class)->retry($delivery));
        $this->assertSame(1, $delivery->fresh()->retries);

        Picky::$refuse = [];
        $this->assertTrue(app(FailedDeliveries::class)->retry($delivery->fresh()));
        $this->assertSame(0, FailedDelivery::query()->count());
        $this->assertSame(['a', 'bad'], Record::query()->orderBy('rkey')->pluck('rkey')->all());
    }

    public function test_hooks_reject_creates_and_updates_but_never_deletes(): void
    {
        config(['atp-appview.ingest.hooks' => [Spammy::class]]);
        $this->deliverThroughConfig([$this->event(1, 'a'), $this->event(2, 'spam', record: ['text' => 'buy now'])]);

        $this->assertSame(['a'], Record::query()->pluck('rkey')->all());
        $this->assertSame([['hook' => Spammy::class, 'collection' => 'app.example.post', 'reason' => 'looks like spam', 'total' => 1, 'last_uri' => 'at://did:plc:alice/app.example.post/spam']], array_map(
            fn (array $row) => array_intersect_key($row, array_flip(['hook', 'collection', 'reason', 'total', 'last_uri'])),
            app(Rejections::class)->all(),
        ));

        Spammy::$seen = [];
        $this->deliverThroughConfig([$this->event(3, 'a', RecordAction::Delete)]);
        $this->assertNotContains('a', Spammy::$seen, 'A delete is never offered to a hook.');
        $this->assertSame(0, Record::query()->count());
    }

    public function test_hooks_can_be_applied_to_records_indexed_before_them(): void
    {
        $this->deliverThroughConfig([$this->event(1, 'a'), $this->event(2, 'spam', record: ['text' => 'buy now'])]);
        config(['atp-appview.ingest.hooks' => [Spammy::class]]);

        $this->artisan('appview:hooks:apply', ['--dry-run' => true])->expectsOutputToContain('Would remove 1 records')->assertSuccessful();
        $this->assertSame(['a', 'spam'], Record::query()->orderBy('rkey')->pluck('rkey')->all(), 'A dry run removes nothing.');
        $this->assertSame([], app(Rejections::class)->all());

        $this->artisan('appview:hooks:apply')->expectsOutputToContain('Removed 1 records')->assertSuccessful();
        $this->assertSame(['a'], Record::query()->pluck('rkey')->all());
        $this->assertNotNull(Record::query()->withTrashed()->where('rkey', 'spam')->value('deleted_at'), 'Removed as a delete, so what depends on it goes too.');
        $this->assertSame(1, app(Rejections::class)->all()[0]['total']);

        $this->artisan('appview:hooks:apply')->expectsOutputToContain('Removed 0 records')->assertSuccessful();
    }

    public function test_a_rejection_keeps_hook_and_reason_within_their_columns(): void
    {
        app(Rejections::class)->record(str_repeat('H', 300), $this->event(1, 'spam'), str_repeat('é', 300));

        $row = app(Rejections::class)->all()[0];

        $this->assertSame(str_repeat('H', 191), $row['hook']);
        $this->assertSame(str_repeat('é', 191), $row['reason'], 'Cut by characters, as the 191 character columns count them.');
    }

    public function test_a_rejection_is_tallied_only_once_the_batch_goes_through(): void
    {
        config(['atp-appview.ingest.hooks' => [Spammy::class]]);
        Picky::$down = true;
        $pipeline = new \SocialDept\AtpAppview\Ingest\Pipeline(app(), [new Picky()], [\SocialDept\AtpAppview\Ingest\Pipes\RunHooks::class]);

        try {
            $pipeline->run(new Batch([$this->event(1, 'spam', record: ['text' => 'buy now']), $this->event(2, 'b')]));
            $this->fail('Expected the attempt to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], app(Rejections::class)->all(), 'A failed attempt tallies nothing.');
    }

    public function test_an_app_can_add_its_own_pipe(): void
    {
        config(['atp-appview.ingest.pipes' => [Counting::class]]);
        Counting::$after = 0;

        $this->deliverThroughConfig([$this->event(1, 'a'), $this->event(2, 'b')]);

        $this->assertSame(2, Counting::$after, 'Code after $next() runs once the handlers have the batch.');
    }

    public function test_a_repair_rewrites_a_record_before_the_hooks_and_keeps_the_original(): void
    {
        config(['atp-appview.ingest.repairs' => [SlashDates::class], 'atp-appview.ingest.hooks' => [Spammy::class]]);
        Picky::$isoDates = true;

        $this->deliverThroughConfig([$this->event(1, 'a', record: ['createdAt' => '2026/10/01']), $this->event(2, 'b', record: ['createdAt' => '2026-10-02T00:00:00Z'])]);

        $repaired = Record::query()->find('at://did:plc:alice/app.example.post/a');
        $this->assertSame('2026-10-01T00:00:00Z', $repaired->record['createdAt'], 'The handlers took the repaired record.');
        $this->assertSame(['by' => [SlashDates::class], 'original' => ['text' => 'a', 'createdAt' => '2026/10/01']], $repaired->repair);
        $this->assertSame('bafy-a', $repaired->cid, 'The CID is still the one in the repo.');
        $this->assertSame(['2026-10-01T00:00:00Z'], Spammy::$dates, 'Hooks see the repaired record.');
        $this->assertNull(Record::query()->find('at://did:plc:alice/app.example.post/b')->repair, 'A record no repair changed carries nothing.');

        $this->assertSame([['repair' => SlashDates::class, 'collection' => 'app.example.post', 'total' => 1, 'last_uri' => 'at://did:plc:alice/app.example.post/a']], array_map(
            fn (array $row) => array_intersect_key($row, array_flip(['repair', 'collection', 'total', 'last_uri'])),
            app(Repairs::class)->all(),
        ));

        // The author fixes the record: the repair is gone with the old version.
        $this->deliverThroughConfig([new RecordEvent(3, 'did:plc:alice', 'app.example.post', 'a', RecordAction::Update, 'bafy-a2', '3l2aaaaaaab3', ['text' => 'a', 'createdAt' => '2026-10-01T00:00:00Z'], true)]);
        $this->assertNull(Record::query()->find('at://did:plc:alice/app.example.post/a')->repair);
    }

    public function test_a_repair_is_tallied_only_once_the_batch_goes_through(): void
    {
        config(['atp-appview.ingest.repairs' => [SlashDates::class]]);
        Picky::$down = true;

        try {
            (new Pipeline(app(), [new Picky()], [RunRepairs::class]))->run(new Batch([$this->event(1, 'a', record: ['createdAt' => '2026/10/01'])]));
            $this->fail('Expected the attempt to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], app(Repairs::class)->all(), 'A failed attempt tallies nothing.');
    }

    public function test_a_failed_delivery_goes_through_once_a_repair_for_it_is_added(): void
    {
        Picky::$isoDates = true;
        $this->deliverThroughConfig([$this->event(1, 'a', record: ['createdAt' => '2026/10/01']), $this->event(2, 'z')]);
        $delivery = FailedDelivery::query()->sole();
        $this->assertSame('2026/10/01', $delivery->payload['record']['createdAt'], 'The original is what is parked.');

        config(['atp-appview.ingest.repairs' => [SlashDates::class]]);
        $this->assertTrue(app(FailedDeliveries::class)->retry($delivery));
        $this->assertSame('2026-10-01T00:00:00Z', Record::query()->find('at://did:plc:alice/app.example.post/a')->record['createdAt']);
    }

    public function test_reindex_indexes_the_record_as_its_pds_has_it_now(): void
    {
        Picky::$isoDates = true;
        $this->deliverThroughConfig([$this->event(1, 'a', record: ['createdAt' => '2026/10/01']), $this->event(2, 'z')]);

        // The author has since fixed it.
        Resolver::shouldReceive('resolvePds')->with('did:plc:alice')->andReturn('https://pds.example');
        $atp = Atp::fake();
        $atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => '3l2zzzzzzzzzz']);
        $atp->stub('com.atproto.repo.getRecord', ['uri' => 'at://did:plc:alice/app.example.post/a', 'cid' => 'bafy-fixed', 'value' => ['text' => 'a', 'createdAt' => '2026-10-01T00:00:00Z']]);

        $this->assertTrue(app(FailedDeliveries::class)->reindex(FailedDelivery::query()->sole()));

        $record = Record::query()->find('at://did:plc:alice/app.example.post/a');
        $this->assertSame('2026-10-01T00:00:00Z', $record->record['createdAt']);
        $this->assertSame('bafy-fixed', $record->cid);
        $this->assertSame('3l2zzzzzzzzzz', $record->rev);
        $this->assertSame(0, FailedDelivery::query()->count());
    }

    public function test_reindex_tombstones_a_record_the_author_deleted_and_keeps_one_it_cannot_fetch(): void
    {
        Picky::$refuse = ['bad', 'worse'];
        $this->deliver([$this->event(1, 'bad'), $this->event(2, 'worse'), $this->event(3, 'z')]);
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        $atp = Atp::fake();
        $atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => '3l2zzzzzzzzzz']);
        $atp->stub('com.atproto.repo.getRecord', FakeResponse::error('RecordNotFound', 'Could not locate record', 400));

        [$bad, $worse] = FailedDelivery::query()->orderBy('id')->get()->all();
        Picky::$refuse = [];
        $this->assertTrue(app(FailedDeliveries::class)->reindex($bad));
        $this->assertNotNull(Record::withTrashed()->find('at://did:plc:alice/app.example.post/bad')->deleted_at);

        $atp->stub('com.atproto.repo.getRecord', FakeResponse::error('InternalServerError', 'The PDS is down', 500));
        $this->assertFalse(app(FailedDeliveries::class)->reindex($worse));
        $this->assertSame(1, $worse->fresh()->retries);
    }

    public function test_an_identity_event_cannot_be_reindexed_and_the_command_skips_it(): void
    {
        $identity = app(FailedDeliveries::class)->park(new IdentityEvent(7, 'did:plc:alice', 'alice.test', true, null), new RuntimeException('no'));

        try {
            app(FailedDeliveries::class)->reindex($identity);
            $this->fail('Expected identity events to be refused.');
        } catch (LogicException) {
        }

        $this->artisan('appview:failed-deliveries', ['--reindex' => true])->expectsOutputToContain('0 went through, 0 failed again')->assertSuccessful();
        $this->assertSame(1, FailedDelivery::query()->count());
    }

    public function test_prune_keeps_what_keep_days_and_max_allow(): void
    {
        foreach ([200, 100, 10, 1, 0] as $i => $daysAgo) {
            FailedDelivery::query()->create(['kind' => 'record', 'did' => 'did:plc:a', 'payload' => [], 'error_class' => 'E', 'error' => 'e', 'failed_at' => Carbon::now('UTC')->subDays($daysAgo)]);
        }

        config(['atp-appview.failed_deliveries.keep_days' => 90, 'atp-appview.failed_deliveries.max' => 2]);
        $this->artisan('appview:failed-deliveries', ['--prune' => true])->assertSuccessful();

        $this->assertSame([1, 0], FailedDelivery::query()->orderBy('id')->get()->map(fn ($delivery) => (int) round($delivery->failed_at->diffInDays(Carbon::now('UTC'), true)))->all());

        config(['atp-appview.failed_deliveries.keep_days' => null, 'atp-appview.failed_deliveries.max' => null]);
        $this->assertSame(0, app(FailedDeliveries::class)->prune(), 'Null keeps them all.');
    }

    public function test_the_command_shows_retries_and_forgets(): void
    {
        $this->deliver([$this->event(1, 'bad'), $this->event(2, 'a')]);

        $this->artisan('appview:failed-deliveries')->expectsTable(['Collection', 'Error', 'Count', 'Latest'], [
            ['app.example.post', 'RuntimeException', '1', FailedDelivery::query()->value('failed_at')->format('Y-m-d H:i:s')],
        ])->assertSuccessful();

        $this->artisan('appview:failed-deliveries', ['--retry' => true])->assertFailed();

        Picky::$refuse = [];
        $this->artisan('appview:failed-deliveries', ['--retry' => true])->assertSuccessful();
        $this->assertSame(0, FailedDelivery::query()->count());

        FailedDelivery::query()->create(['kind' => 'record', 'did' => 'did:plc:a', 'collection' => 'app.example.post', 'payload' => [], 'error_class' => 'E', 'error' => 'e', 'failed_at' => now()]);
        $this->artisan('appview:failed-deliveries', ['--forget' => true])->assertSuccessful();
        $this->assertSame(0, FailedDelivery::query()->count());
    }

    /**
     * @param  array<int, RecordEvent>  $events
     */
    protected function deliverThroughConfig(array $events): void
    {
        $buffer = new IngestBuffer(
            [app(RecordIndexer::class), new Picky()],
            function (int $id) {
                $this->acked[] = $id;
            },
            maxAttempts: 1,
            sleep: fn () => null,
            failedDeliveries: app(FailedDeliveries::class),
        );

        foreach ($events as $event) {
            $buffer->push($event->id, $event);
        }

        $buffer->flush();
    }
}

/**
 * A handler that refuses some records, or everything while "down".
 */
class Picky implements BatchHandler
{
    /** @var array<int, string> */
    public static array $refuse = [];

    public static bool $down = false;

    /** Refuse dates that are not ISO 8601, as a strict index might. */
    public static bool $isoDates = false;

    public function handle(Batch $batch): void
    {
        if (self::$down) {
            throw new RuntimeException('The database is down');
        }

        foreach ($batch->records() as $event) {
            if (in_array($event->rkey, self::$refuse, true)) {
                throw new RuntimeException("Picky refuses {$event->rkey}");
            }

            if (self::$isoDates && isset($event->record['createdAt']) && ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $event->record['createdAt'])) {
                throw new RuntimeException("Picky refuses the date of {$event->rkey}");
            }
        }
    }
}

class Spammy implements IngestHook
{
    /** @var array<int, string> */
    public static array $seen = [];

    /** @var array<int, string> */
    public static array $dates = [];

    public function reject(array $records): array
    {
        $rejected = [];

        foreach ($records as $event) {
            self::$seen[] = $event->rkey;

            if ($event->rkey === 'a' && isset($event->record['createdAt'])) {
                self::$dates[] = $event->record['createdAt'];
            }

            if (str_contains((string) ($event->record['text'] ?? ''), 'buy now')) {
                $rejected[$event->uri()] = 'looks like spam';
            }
        }

        return $rejected;
    }
}

class Counting
{
    public static int $after = 0;

    public function handle(Batch $batch, Closure $next): mixed
    {
        $result = $next($batch);
        self::$after += $batch->count();

        return $result;
    }
}

/**
 * Rewrites 2026/10/01 as 2026-10-01T00:00:00Z.
 */
class SlashDates implements IngestRepair
{
    public function repair(RecordEvent $record): ?array
    {
        $date = $record->record['createdAt'] ?? null;

        if (! is_string($date) || ! preg_match('#^(\d{4})/(\d{2})/(\d{2})$#', $date, $parts)) {
            return null;
        }

        return [...$record->record, 'createdAt' => "{$parts[1]}-{$parts[2]}-{$parts[3]}T00:00:00Z"];
    }
}
