<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Closure;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Ingest\DeadLetters;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Ingest\Rejections;
use SocialDept\AtpAppview\Models\DeadLetter;
use SocialDept\AtpAppview\Models\Record;

class DeadLettersTest extends TestCase
{
    /** @var array<int, int> */
    protected array $acked = [];

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
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
        Spammy::$seen = [];
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
            deadLetters: app(DeadLetters::class),
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

        $letter = DeadLetter::query()->sole();
        $this->assertSame('at://did:plc:alice/app.example.post/bad', $letter->uri);
        $this->assertSame(RuntimeException::class, $letter->error_class);
        $this->assertSame('Picky refuses bad', $letter->error);
        $this->assertSame('bad', $letter->payload['rkey']);
    }

    public function test_when_everything_fails_the_batch_goes_back_to_tap_until_it_has_failed_in_several_deliveries(): void
    {
        config(['atp-appview.dead_letters.max_deliveries' => 3]);
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
        $this->assertSame(0, DeadLetter::query()->count());

        // The third delivery fails too: now both are parked and acknowledged.
        $this->deliver($events);
        $this->assertSame([1, 2], $this->acked);
        $this->assertSame(2, DeadLetter::query()->count());
    }

    public function test_a_poison_event_delivered_by_webhook_is_parked_and_the_request_succeeds(): void
    {
        $this->postJson('/atp-appview/tap', ['events' => [
            ['id' => 1, 'type' => 'record', 'record' => ['live' => true, 'rev' => '3l2aaaaaaaaa1', 'did' => 'did:plc:alice', 'collection' => 'app.example.post', 'rkey' => 'a', 'action' => 'create', 'cid' => 'bafy-a', 'record' => ['text' => 'a']]],
            ['id' => 2, 'type' => 'record', 'record' => ['live' => true, 'rev' => '3l2aaaaaaaaa2', 'did' => 'did:plc:alice', 'collection' => 'app.example.post', 'rkey' => 'bad', 'action' => 'create', 'cid' => 'bafy-bad', 'record' => ['text' => 'bad']]],
        ]], ['Authorization' => 'Basic '.base64_encode('admin:secret')])->assertOk();

        $this->assertSame(['a'], Record::query()->pluck('rkey')->all());
        $this->assertSame('bad', DeadLetter::query()->sole()->payload['rkey']);
    }

    public function test_a_dead_letter_is_retried_once_the_cause_is_fixed(): void
    {
        $this->deliver([$this->event(1, 'a'), $this->event(2, 'bad')]);
        $letter = DeadLetter::query()->sole();

        // Still broken: kept, with the attempt noted.
        $this->assertFalse(app(DeadLetters::class)->retry($letter));
        $this->assertSame(1, $letter->fresh()->retries);

        Picky::$refuse = [];
        $this->assertTrue(app(DeadLetters::class)->retry($letter->fresh()));
        $this->assertSame(0, DeadLetter::query()->count());
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

    public function test_prune_keeps_what_keep_days_and_max_allow(): void
    {
        foreach ([200, 100, 10, 1, 0] as $i => $daysAgo) {
            DeadLetter::query()->create(['kind' => 'record', 'did' => 'did:plc:a', 'payload' => [], 'error_class' => 'E', 'error' => 'e', 'failed_at' => Carbon::now('UTC')->subDays($daysAgo)]);
        }

        config(['atp-appview.dead_letters.keep_days' => 90, 'atp-appview.dead_letters.max' => 2]);
        $this->artisan('appview:dead-letters', ['--prune' => true])->assertSuccessful();

        $this->assertSame([1, 0], DeadLetter::query()->orderBy('id')->get()->map(fn ($letter) => (int) round($letter->failed_at->diffInDays(Carbon::now('UTC'), true)))->all());

        config(['atp-appview.dead_letters.keep_days' => null, 'atp-appview.dead_letters.max' => null]);
        $this->assertSame(0, app(DeadLetters::class)->prune(), 'Null keeps them all.');
    }

    public function test_the_command_shows_retries_and_dismisses(): void
    {
        $this->deliver([$this->event(1, 'bad'), $this->event(2, 'a')]);

        $this->artisan('appview:dead-letters')->expectsTable(['Collection', 'Error', 'Count', 'Latest'], [
            ['app.example.post', 'RuntimeException', '1', DeadLetter::query()->value('failed_at')->format('Y-m-d H:i:s')],
        ])->assertSuccessful();

        $this->artisan('appview:dead-letters', ['--retry' => true])->assertFailed();

        Picky::$refuse = [];
        $this->artisan('appview:dead-letters', ['--retry' => true])->assertSuccessful();
        $this->assertSame(0, DeadLetter::query()->count());

        DeadLetter::query()->create(['kind' => 'record', 'did' => 'did:plc:a', 'collection' => 'app.example.post', 'payload' => [], 'error_class' => 'E', 'error' => 'e', 'failed_at' => now()]);
        $this->artisan('appview:dead-letters', ['--dismiss' => true])->assertSuccessful();
        $this->assertSame(0, DeadLetter::query()->count());
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
            deadLetters: app(DeadLetters::class),
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

    public function handle(Batch $batch): void
    {
        if (self::$down) {
            throw new RuntimeException('The database is down');
        }

        foreach ($batch->records() as $event) {
            if (in_array($event->rkey, self::$refuse, true)) {
                throw new RuntimeException("Picky refuses {$event->rkey}");
            }
        }
    }
}

class Spammy implements IngestHook
{
    /** @var array<int, string> */
    public static array $seen = [];

    public function reject(array $records): array
    {
        $rejected = [];

        foreach ($records as $event) {
            self::$seen[] = $event->rkey;

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
