<?php

namespace SocialDept\AtpAppview\Tests\Feature\Views;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ShelfView;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ThingView;
use SocialDept\AtpAppview\Views\ReplyTree;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * A thread built from parent references: the anchor's replies as a tree,
 * its parents above it, and placeholders where the viewer cannot see one.
 */
class ReplyTreeTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const DAVE = 'did:plc:dave';
    protected const VIEWER = 'did:plc:viewer';
    protected const THING = 'com.example.thing';
    protected const BLOCK = 'com.example.block';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.views', [ThingView::class, ShelfView::class]);
        $app['config']->set('atp-appview.graph.blocks', ['collection' => self::BLOCK, 'path' => 'subject']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    protected function thing(string $did, string $rkey, ?string $parent = null): string
    {
        $record = ['text' => $rkey, 'createdAt' => '2026-10-01T00:00:00Z'] + ($parent === null ? [] : ['reply' => ['parent' => ['uri' => $parent]]]);
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, self::THING, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', $record, true),
        ]));

        return 'at://'.$did.'/'.self::THING.'/'.$rkey;
    }

    /**
     * The tree's shape as nested [type, rkey, replies] for comparing.
     */
    protected function shape(array $node): array
    {
        return [$node['type'], explode('/', $node['uri'])[4], array_map(fn (array $reply) => $this->shape($reply), $node['replies'] ?? [])];
    }

    public function test_replies_come_as_a_tree_leaving_out_what_the_viewer_cannot_see(): void
    {
        $root = $this->thing(self::ALICE, 'root');
        $a = $this->thing(self::BOB, 'a', $root);
        $this->thing(self::CAROL, 'b', $a);
        $this->thing(self::DAVE, 'c', $root);
        $this->thing(self::BOB, 'd', $root);
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, self::VIEWER, self::BLOCK, 'k1', RecordAction::Create, 'bafy-k1', '3l2aaaaaaaaaa', ['subject' => self::DAVE, 'createdAt' => '2026-10-01T00:00:00Z'], true),
        ]));

        $parents = ReplyTree::parents(Record::query()->where('collection', self::THING)->orderBy('id')->get(), 'reply.parent.uri');
        $tree = app(ReplyTree::class)->build($root, $parents, self::VIEWER);

        $this->assertSame(['record', 'root', [['record', 'a', [['record', 'b', []]]], ['record', 'd', []]]], $this->shape($tree), 'Dave is blocked, so his reply is left out.');
        $this->assertSame('a', $tree['replies'][0]['view']['record']['text'], 'Each node carries its view.');
        $this->assertArrayHasKey('author', $tree['replies'][0]['view']);
        $this->assertArrayNotHasKey('parent', $tree, 'The root has no parent.');

        $this->assertSame(['record', 'root', [['record', 'a', []], ['record', 'd', []]]], $this->shape(app(ReplyTree::class)->build($root, $parents, self::VIEWER, depth: 1)));

        $newestFirst = app(ReplyTree::class)->build($root, $parents, self::VIEWER, order: fn (array $x, array $y) => strcmp($y['uri'], $x['uri']));
        $this->assertSame(['d', 'a'], array_map(fn (array $reply) => explode('/', $reply['uri'])[4], $newestFirst['replies']));
    }

    public function test_parents_stand_above_the_anchor_with_placeholders(): void
    {
        $root = $this->thing(self::ALICE, 'root');
        $a = $this->thing(self::DAVE, 'a', $root);
        $b = $this->thing(self::BOB, 'b', $a);
        $gone = 'at://'.self::CAROL.'/'.self::THING.'/gone';
        $c = $this->thing(self::BOB, 'c', $gone);
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, self::DAVE, self::BLOCK, 'k1', RecordAction::Create, 'bafy-k1', '3l2aaaaaaaaaa', ['subject' => self::VIEWER, 'createdAt' => '2026-10-01T00:00:00Z'], true),
        ]));

        $parents = [$root => null, $a => $root, $b => $a, $gone => $root, $c => $gone];
        $tree = app(ReplyTree::class)->build($b, $parents, self::VIEWER);

        $this->assertSame('record', $tree['type']);
        $this->assertSame(['type' => 'blocked', 'uri' => $a, 'author' => self::DAVE], array_diff_key($tree['parent'], ['parent' => true]), 'Dave blocks the viewer: blocked either way.');
        $this->assertSame('record', $tree['parent']['parent']['type'] ?? null, 'The chain goes on above a placeholder.');
        $this->assertSame($root, $tree['parent']['parent']['uri']);

        $this->assertSame(['type' => 'notFound', 'uri' => $gone], array_diff_key(app(ReplyTree::class)->build($c, $parents, self::VIEWER)['parent'], ['parent' => true]));
        $this->assertSame(['type' => 'notFound', 'uri' => $gone], array_diff_key(app(ReplyTree::class)->build($gone, $parents, self::VIEWER), ['parent' => true]), 'An anchor the viewer cannot see is a placeholder, with no replies.');
        $this->assertArrayNotHasKey('parent', app(ReplyTree::class)->build($b, $parents, self::VIEWER, height: 0));
    }

    public function test_a_deeper_thread_takes_no_more_queries(): void
    {
        $queries = function (int $length): int {
            $parent = $root = $this->thing(self::ALICE, "root{$length}");

            for ($i = 0; $i < $length; $i++) {
                $parent = $this->thing(self::BOB, "r{$length}-{$i}", $parent);
            }

            $parents = ReplyTree::parents(Record::query()->where('uri', 'like', '%/root'.$length)->orWhere('uri', 'like', "%/r{$length}-%")->get(), 'reply.parent.uri');
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            app(ReplyTree::class)->build($root, $parents, self::VIEWER, depth: 10);
            $count = count(\DB::getQueryLog());
            \DB::disableQueryLog();

            return $count;
        };

        // The first build also warms the index key cache.
        $queries(1);
        $this->assertSame($queries(2), $queries(8));
    }

    public function test_a_loop_in_the_references_ends(): void
    {
        $a = $this->thing(self::ALICE, 'a');
        $b = $this->thing(self::BOB, 'b', $a);
        $parents = [$a => $b, $b => $a];

        $tree = app(ReplyTree::class)->build($a, $parents, null);

        $this->assertSame(['record', 'a', [['record', 'b', []]]], $this->shape($tree));
        $this->assertSame($b, $tree['parent']['uri']);
        $this->assertArrayNotHasKey('parent', $tree['parent']);
    }
}
