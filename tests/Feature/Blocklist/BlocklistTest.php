<?php

namespace SocialDept\AtpAppview\Tests\Feature\Blocklist;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Models\Block;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

class BlocklistTest extends TestCase
{
    protected const TAP = 'http://tap.test';
    protected const PLC = 'https://plc.test';
    protected const RELAY = 'https://relay.test';

    protected bool $tapDown = false;

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-appview.ingest.admin_url', self::TAP);
        $app['config']->set('atp-appview.ingest.discovery_url', self::RELAY);
        $app['config']->set('atp-support.plc_directory', self::PLC);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        // Every DID document puts its repo on <name>.pds.test, except spam
        // DIDs, which live on a spam host.
        Http::fake([
            self::TAP.'/*' => fn () => $this->tapDown ? Http::response('down', 503) : Http::response([], 200),
            self::RELAY.'/*' => Http::response(['repos' => [['did' => 'did:plc:bad'], ['did' => 'did:plc:spam1'], ['did' => 'did:plc:part'], ['did' => 'did:plc:good']]]),
            self::PLC.'/*' => function (Request $request) {
                $did = substr(parse_url($request->url(), PHP_URL_PATH), 1);
                $name = substr($did, strlen('did:plc:'));
                $host = str_starts_with($name, 'spam') ? "{$name}.spam.example" : "{$name}.pds.test";

                return $name === 'gone'
                    ? Http::response(['message' => 'DID not registered'], 404)
                    : Http::response(['id' => $did, 'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => "https://{$host}"]]]);
            },
        ]);
    }

    public function test_a_did_rule_drops_its_records_in_every_collection(): void
    {
        $this->blocklist()->block('did:plc:bad', note: 'spam ring');

        $batch = $this->filter([
            $this->record('did:plc:bad', 'app.example.post'),
            $this->record('did:plc:bad', 'app.example.like'),
            $this->record('did:plc:good', 'app.example.post'),
            new IdentityEvent(9, 'did:plc:bad', 'bad.test', true, null),
        ]);

        $this->assertSame(['did:plc:good'], array_map(fn ($e) => $e->did, $batch->records()));
        $this->assertCount(1, $batch->identities(), 'Identity events always pass.');

        $block = Block::sole();
        $this->assertSame(2, $block->blocked_count);
        $this->assertNotNull($block->last_blocked_at);
        $this->assertSame('spam ring', $block->note);
    }

    public function test_a_collection_rule_drops_only_that_collection_or_prefix(): void
    {
        $this->blocklist()->block('did:plc:bad', 'app.example.like');
        $this->blocklist()->block('did:plc:worse', 'app.example.graph.*');

        $batch = $this->filter([
            $this->record('did:plc:bad', 'app.example.like'),
            $this->record('did:plc:bad', 'app.example.post'),
            $this->record('did:plc:worse', 'app.example.graph.follow'),
            $this->record('did:plc:worse', 'app.example.post'),
        ]);

        $this->assertSame(
            ['did:plc:bad app.example.post', 'did:plc:worse app.example.post'],
            array_map(fn ($e) => "{$e->did} {$e->collection}", $batch->records()),
        );
    }

    public function test_a_host_rule_resolves_each_pds_once_and_drops_what_it_hosts(): void
    {
        $this->blocklist()->block('*.spam.example');

        $events = [
            $this->record('did:plc:spam1', 'app.example.post'),
            $this->record('did:plc:alice', 'app.example.post'),
            $this->record('did:plc:gone', 'app.example.post'),
        ];

        $batch = $this->filter($events);
        $this->filter($events);

        $this->assertSame(['did:plc:alice', 'did:plc:gone'], array_map(fn ($e) => $e->did, $batch->records()), 'A DID document that cannot be fetched never blocks a repo.');
        $this->assertSame('https://spam1.spam.example', Identity::find('did:plc:spam1')->pds);
        $this->assertCount(3, $this->sentTo(self::PLC), 'Resolved PDS hosts are kept, not looked up again.');
    }

    public function test_no_pds_is_looked_up_without_a_host_rule(): void
    {
        $this->blocklist()->block('did:plc:bad');

        $this->filter([$this->record('did:plc:alice', 'app.example.post')]);

        $this->assertCount(0, $this->sentTo(self::PLC));
    }

    public function test_a_running_consumer_picks_up_a_rule_added_elsewhere(): void
    {
        $running = $this->app->make(Blocklist::class);
        $event = $this->record('did:plc:bad', 'app.example.post');

        $this->assertCount(1, $running->filter(new Batch([$event]))->records());

        // Another process, such as appview:block, with its own instance.
        $this->newBlocklist()->block('did:plc:bad');

        $this->assertCount(0, $running->filter(new Batch([$event]))->records());
    }

    public function test_a_blanket_rule_stops_tap_following_the_repo_and_a_collection_rule_does_not(): void
    {
        $this->blocklist()->block('did:plc:part', 'app.example.like');
        $this->assertCount(0, $this->sentTo(self::TAP.'/repos/remove'));

        $outcome = $this->blocklist()->block('did:plc:bad');

        $this->assertSame(['did:plc:bad'], $outcome->removedFromTap);
        $this->assertSame([['did:plc:bad']], array_map(fn (Request $r) => $r['dids'], $this->sentTo(self::TAP.'/repos/remove')));
    }

    public function test_a_blanket_host_rule_removes_known_repos_on_that_host_from_tap(): void
    {
        $this->app->make(\SocialDept\AtpAppview\Blocklist\PdsLookup::class)->resolve(['did:plc:spam1', 'did:plc:alice']);

        $outcome = $this->blocklist()->block('*.spam.example');

        $this->assertSame(['did:plc:spam1'], $outcome->removedFromTap);
    }

    public function test_a_blocked_repo_tap_follows_again_is_removed_again(): void
    {
        $this->blocklist()->block('did:plc:bad');
        $this->blocklist()->block('did:plc:part', 'app.example.like');
        $before = count($this->sentTo(self::TAP.'/repos/remove'));

        // Tap re-added the repo and is backfilling it.
        $this->filter([new IdentityEvent(1, 'did:plc:bad', 'bad.test', true, null), $this->record('did:plc:bad', 'app.example.post')]);
        $this->filter([$this->record('did:plc:bad', 'app.example.post')]);
        $this->filter([$this->record('did:plc:part', 'app.example.like')]);

        $removals = array_slice($this->sentTo(self::TAP.'/repos/remove'), $before);

        $this->assertSame([['did:plc:bad']], array_map(fn (Request $r) => $r['dids'], $removals), 'Once per repo per interval, and never for a rule that covers only some collections.');
    }

    public function test_a_failed_removal_never_fails_the_batch(): void
    {
        $this->blocklist()->block('did:plc:bad');
        $this->tapDown = true;

        $batch = $this->filter([$this->record('did:plc:bad', 'app.example.post'), $this->record('did:plc:good', 'app.example.post')]);

        $this->assertSame(['did:plc:good'], array_map(fn ($e) => $e->did, $batch->records()));
    }

    public function test_a_tap_failure_keeps_the_rule(): void
    {
        $this->tapDown = true;

        $outcome = $this->blocklist()->block('did:plc:bad');

        $this->assertNotNull($outcome->tapError);
        $this->assertSame(1, Block::count());
        $this->assertCount(0, $this->filter([$this->record('did:plc:bad', 'app.example.post')])->records());
    }

    public function test_purging_deletes_what_the_rule_covers(): void
    {
        $this->indexRecords([
            $this->record('did:plc:bad', 'app.example.post'),
            $this->record('did:plc:bad', 'app.example.like'),
            $this->record('did:plc:good', 'app.example.like'),
        ]);

        $outcome = $this->blocklist()->block('did:plc:bad', 'app.example.like', purge: true);

        $this->assertSame(1, $outcome->purged);
        $this->assertSame(['did:plc:bad app.example.post', 'did:plc:good app.example.like'], $this->indexed());
    }

    public function test_purging_a_host_resolves_unknown_repos_first(): void
    {
        $this->indexRecords([
            $this->record('did:plc:spam1', 'app.example.post'),
            $this->record('did:plc:spam2', 'app.example.post'),
            $this->record('did:plc:alice', 'app.example.post'),
        ]);

        $outcome = $this->blocklist()->block('https://*.spam.example/', purge: true);

        $this->assertSame(2, $outcome->purged);
        $this->assertSame(['did:plc:alice app.example.post'], $this->indexed());
    }

    public function test_unblocking_has_tap_backfill_what_the_rule_dropped(): void
    {
        $this->blocklist()->block('did:plc:bad');
        $this->blocklist()->block('did:plc:part', 'app.example.like');

        $this->assertSame(['did:plc:bad'], $this->blocklist()->unblock('did:plc:bad')->resyncedInTap);
        $this->assertSame(['did:plc:part'], $this->blocklist()->unblock('did:plc:part', 'app.example.like')->resyncedInTap);
        $this->assertNull($this->blocklist()->unblock('did:plc:nobody'));

        $calls = array_map(fn (Request $r) => basename(parse_url($r->url(), PHP_URL_PATH)).' '.implode(',', $r['dids']), array_slice($this->sentTo(self::TAP), 1));

        // A blanket rule had Tap drop the repo, so adding it back backfills
        // it. Tap still follows a repo under a collection rule, so it is
        // removed and added again to force a backfill.
        $this->assertSame(['add did:plc:bad', 'remove did:plc:part', 'add did:plc:part'], $calls);
        $this->assertSame(0, Block::count());
    }

    public function test_the_buffer_hands_handlers_the_filtered_batch_and_acknowledges_everything(): void
    {
        $this->blocklist()->block('did:plc:bad');
        $acked = [];
        $seen = [];

        $buffer = new IngestBuffer([new class ($seen) implements \SocialDept\AtpAppview\Ingest\Contracts\BatchHandler {
            public function __construct(public array &$seen)
            {
            }

            public function handle(Batch $batch): void
            {
                $this->seen[] = array_map(fn ($e) => $e->did, $batch->records());
            }
        }], function (int $id) use (&$acked) {
            $acked[] = $id;
        }, blocklist: $this->app->make(Blocklist::class));

        $buffer->push(1, $this->record('did:plc:bad', 'app.example.post'));
        $buffer->push(2, $this->record('did:plc:good', 'app.example.post'));
        $buffer->flush();

        $buffer->push(3, $this->record('did:plc:bad', 'app.example.post'));
        $buffer->flush();

        $this->assertSame([['did:plc:good']], $seen, 'A batch with nothing left reaches no handler.');
        $this->assertSame([1, 2, 3], $acked);
    }

    public function test_discovery_leaves_out_repos_under_a_blanket_rule(): void
    {
        $this->app->make(\SocialDept\AtpAppview\Blocklist\PdsLookup::class)->resolve(['did:plc:spam1']);
        $this->blocklist()->block('did:plc:bad');
        $this->blocklist()->block('*.spam.example');
        $this->blocklist()->block('did:plc:part', 'app.example.like');

        $this->artisan('appview:discover', ['collection' => ['app.example.post']])
            ->expectsOutputToContain('2 unique repos sent to Tap')
            ->expectsOutputToContain('Left out by the blocklist')
            ->assertSuccessful();

        // The removals came from the blanket rules themselves.
        $added = collect($this->sentTo(self::TAP.'/repos/add'))->flatMap(fn (Request $r) => $r['dids'])->all();
        $this->assertSame(['did:plc:part', 'did:plc:good'], $added);
    }

    protected function blocklist(): Blocklist
    {
        return $this->app->make(Blocklist::class);
    }

    protected function newBlocklist(): Blocklist
    {
        return $this->app->build(Blocklist::class);
    }

    /**
     * @param  array<int, RecordEvent|IdentityEvent>  $events
     */
    protected function filter(array $events): Batch
    {
        return $this->blocklist()->filter(new Batch($events));
    }

    /**
     * @param  array<int, RecordEvent>  $events
     */
    protected function indexRecords(array $events): void
    {
        $this->app->make(RecordIndexer::class)->handle(new Batch($events));
    }

    /**
     * @return array<int, string>
     */
    protected function indexed(): array
    {
        return Record::withTrashed()->orderBy('did')->orderBy('collection')->get()->map(fn (Record $r) => "{$r->did} {$r->collection}")->all();
    }

    /**
     * @return array<int, Request>
     */
    protected function sentTo(string $prefix): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_starts_with($r->url(), $prefix))->values()->all();
    }

    protected function record(string $did, string $collection): RecordEvent
    {
        static $id = 0;
        $id++;

        return new RecordEvent($id, $did, $collection, '3l2aaaaaaaa'.str_pad((string) ($id % 100), 2, 'a'), RecordAction::Create, 'bafy'.$id, '3l2aaaaaaaaaa', ['n' => $id], true);
    }
}
