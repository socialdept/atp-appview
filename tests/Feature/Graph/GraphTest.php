<?php

namespace SocialDept\AtpAppview\Tests\Feature\Graph;

use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpAppview\Views\Parts\Relationship;
use SocialDept\AtpAppview\Views\View;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Follows and blocks read from records in the collections the app names,
 * mutes kept privately, and all three applied where records are listed.
 */
class GraphTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const DAVE = 'did:plc:dave';
    protected const THING = 'com.example.thing';
    protected const FOLLOW = 'com.example.follow';
    protected const BLOCK = 'com.example.block';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.collections', ['com.example.listThings' => self::THING]);
        $app['config']->set('atp-appview.graph.follows', ['collection' => self::FOLLOW, 'path' => 'subject']);
        $app['config']->set('atp-appview.graph.blocks', ['collection' => self::BLOCK, 'path' => 'subject']);
        $app['config']->set('atp-appview.notifications.reasons', [self::THING => ['reply.parent.uri' => 'reply']]);
        $app['config']->set('atp-appview.fields', [self::THING => ['kind' => 'string']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    protected function index(string $did, string $collection, string $rkey, ?array $record, string $rev = '3l2aaaaaaaaaa'): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, $record === null ? RecordAction::Delete : RecordAction::Create, $record === null ? null : "bafy-{$rkey}-{$rev}", $rev, $record, true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function follow(string $did, string $subject, string $rkey): void
    {
        $this->index($did, self::FOLLOW, $rkey, ['subject' => $subject, 'createdAt' => '2026-10-01T00:00:00Z']);
    }

    protected function block(string $did, string $subject, string $rkey): void
    {
        $this->index($did, self::BLOCK, $rkey, ['subject' => $subject, 'createdAt' => '2026-10-01T00:00:00Z']);
    }

    protected function graph(): Graph
    {
        return app(Graph::class);
    }

    public function test_follows_are_read_both_ways_from_records(): void
    {
        $this->follow(self::ALICE, self::BOB, 'f1');
        $this->follow(self::ALICE, self::CAROL, 'f2');
        $this->follow(self::CAROL, self::BOB, 'f3');

        $this->assertTrue($this->graph()->follows(self::ALICE, self::BOB));
        $this->assertFalse($this->graph()->follows(self::BOB, self::ALICE));
        $this->assertEqualsCanonicalizing([self::BOB, self::CAROL], $this->graph()->following(self::ALICE));
        $this->assertEqualsCanonicalizing([self::ALICE, self::CAROL], $this->graph()->followers(self::BOB));

        // An unfollow is a deleted record.
        $this->index(self::ALICE, self::FOLLOW, 'f1', null, '3l2bbbbbbbbbb');
        $this->assertFalse($this->graph()->follows(self::ALICE, self::BOB));
        $this->assertSame([self::CAROL], $this->graph()->following(self::ALICE));
    }

    public function test_blocks_apply_both_ways_and_mutes_only_to_the_muter(): void
    {
        $this->block(self::ALICE, self::BOB, 'b1');
        $this->graph()->mute(self::ALICE, self::CAROL);
        $this->graph()->mute(self::ALICE, self::ALICE);

        $this->assertSame([self::BOB], $this->graph()->blocking(self::ALICE));
        $this->assertSame([self::ALICE], $this->graph()->blockedBy(self::BOB));
        $this->assertSame([self::CAROL], app(Graph::class)->muted(self::ALICE), 'Nobody mutes themselves.');
        $this->assertEqualsCanonicalizing([self::BOB, self::CAROL], app(Graph::class)->hidden(self::ALICE));
        $this->assertSame([self::ALICE], app(Graph::class)->hidden(self::BOB), 'Blocked, Bob does not see Alice either.');
        $this->assertSame([], app(Graph::class)->hidden(self::CAROL), 'A mute is the muter\'s alone.');

        $graph = app(Graph::class);
        $graph->unmute(self::ALICE, self::CAROL);
        $this->assertSame([self::BOB], $graph->hidden(self::ALICE));
    }

    public function test_records_listed_for_a_viewer_leave_out_who_they_cannot_see(): void
    {
        foreach ([self::ALICE, self::BOB, self::CAROL, self::DAVE] as $i => $did) {
            // Bob and Carol newest, so a page left unfiltered would start with them.
            $day = [self::ALICE => 1, self::DAVE => 2, self::BOB => 3, self::CAROL => 4][$did];
            $this->index($did, self::THING, "t{$i}", ['text' => (string) $i, 'kind' => 'note', 'createdAt' => "2026-10-0{$day}T00:00:00Z"]);
        }
        $this->block(self::BOB, self::ALICE, 'b1');
        app(Graph::class)->mute(self::ALICE, self::CAROL);
        $this->follow(self::ALICE, self::DAVE, 'f1');

        $authors = fn ($query) => $query->pluck('did')->sort()->values()->all();

        $this->assertSame([self::ALICE, self::DAVE], $authors(Record::collection(self::THING)->visibleTo(self::ALICE)));
        $this->assertSame([self::BOB, self::CAROL, self::DAVE], $authors(Record::collection(self::THING)->visibleTo(self::BOB)));
        $this->assertCount(4, Record::collection(self::THING)->visibleTo(null)->get());
        $this->assertSame([self::DAVE], $authors(Record::collection(self::THING)->followedBy(self::ALICE)));

        // Filtered through the indexed fields, as a view's filters are.
        $filtered = RecordQuery::in(self::THING)->where('kind', 'note')->visibleTo(self::ALICE)->get()['records'];
        $this->assertSame([self::ALICE, self::DAVE], $authors($filtered));

        // The built-in XRPC list, called as Alice.
        $listed = array_map(fn (array $record) => explode('/', $record['uri'])[2], Xrpc::as(self::ALICE)->query('com.example.listThings')['records']);
        $this->assertEqualsCanonicalizing([self::ALICE, self::DAVE], $listed);
    }

    public function test_notifications_leave_out_blocked_and_muted_actors(): void
    {
        $post = $this->index(self::ALICE, self::THING, 'post', ['text' => 'hi', 'createdAt' => '2026-10-01T00:00:00Z']);
        $this->index(self::BOB, self::THING, 'r1', ['text' => 'a', 'reply' => ['parent' => ['uri' => $post]], 'createdAt' => '2026-10-02T00:00:00Z']);
        $this->index(self::CAROL, self::THING, 'r2', ['text' => 'b', 'reply' => ['parent' => ['uri' => $post]], 'createdAt' => '2026-10-02T00:00:00Z']);

        $this->assertSame(2, app(Notifications::class)->unreadCount(self::ALICE));

        $this->block(self::BOB, self::ALICE, 'b1');
        app(Graph::class)->mute(self::ALICE, self::CAROL);
        $this->assertSame(0, app(Notifications::class)->unreadCount(self::ALICE));

        app(Graph::class)->unmute(self::ALICE, self::CAROL);
        $this->assertSame(1, app(Notifications::class)->unreadCount(self::ALICE));
    }

    public function test_a_view_part_says_how_the_viewer_stands_with_each_author(): void
    {
        $bobs = $this->index(self::BOB, self::THING, 'b', ['text' => 'b', 'createdAt' => '2026-10-01T00:00:00Z']);
        $alices = $this->index(self::ALICE, self::THING, 'a', ['text' => 'a', 'createdAt' => '2026-10-01T00:00:00Z']);
        $this->follow(self::ALICE, self::BOB, 'f1');
        $this->block(self::BOB, self::ALICE, 'b1');
        app(Graph::class)->mute(self::ALICE, self::BOB);

        $view = new class () extends View {
            public function collection(): string
            {
                return 'com.example.thing';
            }

            public function parts(): array
            {
                return ['relationship' => new Relationship()];
            }
        };

        $records = Record::query()->whereIn('uri', [$bobs, $alices])->orderBy('uri')->get();
        $views = collect(app(Hydrator::class)->hydrate($view, $records, self::ALICE))->keyBy('uri');

        $this->assertSame(['following' => true, 'followedBy' => false, 'blocking' => false, 'blockedBy' => true, 'muted' => true], $views[$bobs]['relationship']);
        $this->assertNull($views[$alices]['relationship'], 'Not for the viewer\'s own records.');
        $this->assertNull(app(Hydrator::class)->hydrate($view, $records, null)[0]['relationship'], 'Not without a viewer.');
    }

    public function test_an_app_without_a_graph_still_has_mutes(): void
    {
        config()->set('atp-appview.graph', ['follows' => null, 'blocks' => null]);
        app(Graph::class)->mute(self::ALICE, self::BOB);

        $this->assertSame([], app(Graph::class)->following(self::ALICE));
        $this->assertFalse(app(Graph::class)->follows(self::ALICE, self::BOB));
        $this->assertSame([self::BOB], app(Graph::class)->hidden(self::ALICE));
    }
}
