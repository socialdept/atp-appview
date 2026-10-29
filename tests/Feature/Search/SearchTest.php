<?php

namespace SocialDept\AtpAppview\Tests\Feature\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Search\Search;
use SocialDept\AtpAppview\Standing\Standing;
use SocialDept\AtpAppview\Tests\Fixtures\Search\EmojiNames;
use SocialDept\AtpAppview\Tests\Fixtures\Search\FollowedFirst;
use SocialDept\AtpAppview\Tests\Fixtures\Search\RecordingRanker;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ShelfView;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ThingView;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Search over the index: records by the words of their sources, Latest and
 * Top, and the accounts the index holds, through Bluesky's search shapes
 * under the app's own names.
 */
class SearchTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const DAVE = 'did:plc:dave';
    protected const FAN = 'did:plc:fan';
    protected const THING = 'com.example.thing';
    protected const STATUS = 'com.example.status';
    protected const LIKE = 'com.example.like';
    protected const FOLLOW = 'com.example.follow';
    protected const BLOCK = 'com.example.block';
    protected const PROFILE = 'com.example.actor.profile';

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
        $app['config']->set('atp-appview.views', [ThingView::class, ShelfView::class]);
        $app['config']->set('atp-appview.profiles.sources', [['collection' => self::PROFILE]]);
        $app['config']->set('atp-appview.ingest.collections', [self::THING, self::STATUS, self::PROFILE]);
        $app['config']->set('atp-appview.graph.muted_words', [self::THING => ['text' => ['text']]]);
        $app['config']->set('atp-appview.graph.follows', ['collection' => self::FOLLOW, 'path' => 'subject']);
        $app['config']->set('atp-appview.graph.blocks', ['collection' => self::BLOCK, 'path' => 'subject']);
        $app['config']->set('atp-appview.features', [Features::graph(), Features::search(), Features::standing()]);
        $app['config']->set('atp-appview.search', [
            'collections' => [
                self::THING => [
                    'sources' => ['text' => ['text'], 'tags' => ['tags', 'facets.*.features.*.tag']],
                    'top' => ['counts' => [self::LIKE => ['subject.uri']], 'window_days' => 7],
                ],
                self::STATUS => ['sources' => ['emoji' => [EmojiNames::class]]],
            ],
            'xrpc' => [
                'records' => 'com.example.search.searchRecords',
                'accounts' => 'com.example.search.searchAccounts',
                'typeahead' => 'com.example.search.searchAccountsTypeahead',
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
        $this->travelTo('2026-10-21 12:00:00');
    }

    protected function index(string $did, string $collection, string $rkey, array $record, string $at = '2026-10-21T00:00:00Z'): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', $record + ['createdAt' => $at], true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function account(string $did, string $handle, ?string $name = null, ?string $description = null): void
    {
        DB::table('atp_identities')->insert(['did' => $did, 'handle' => $handle, 'active' => true, 'updated_at' => now()]);

        if ($name !== null || $description !== null) {
            $this->index($did, self::PROFILE, 'self', array_filter(['displayName' => $name, 'description' => $description]));
        }
    }

    /**
     * @return array<int, string> The rkeys of the records found
     */
    protected function found(array $params, ?string $as = null): array
    {
        return array_map(fn (array $view) => explode('/', $view['uri'])[4], Xrpc::as($as)->query('com.example.search.searchRecords', $params)['records']);
    }

    public function test_latest_finds_records_holding_every_word_newest_first_a_page_at_a_time(): void
    {
        $this->index(self::ALICE, self::THING, 'a', ['text' => 'Cats are great'], '2026-10-21T01:00:00Z');
        $this->index(self::BOB, self::THING, 'b', ['text' => 'great CATS, truly'], '2026-10-21T03:00:00Z');
        $this->index(self::CAROL, self::THING, 'c', ['text' => 'dogs are great'], '2026-10-21T04:00:00Z');
        $this->index(self::DAVE, self::THING, 'd', ['text' => 'cats nap'], '2026-10-21T02:00:00Z');

        $first = Xrpc::as(null)->query('com.example.search.searchRecords', ['q' => 'great cats', 'limit' => 1]);
        $this->assertSame(['b'], array_map(fn ($view) => explode('/', $view['uri'])[4], $first['records']));
        $this->assertSame(2, $first['hitsTotal']);
        $this->assertSame('great CATS, truly', $first['records'][0]['record']['text']);
        $this->assertArrayHasKey('author', $first['records'][0], 'Results are views.');

        $this->assertSame(['a'], $this->found(['q' => 'great cats', 'limit' => 1, 'cursor' => $first['cursor']]));
        $this->assertSame(['b', 'd', 'a'], $this->found(['q' => 'cats']));
    }

    public function test_tags_and_derived_text_are_searched_and_the_tag_filter_reads_only_tags(): void
    {
        $this->index(self::ALICE, self::THING, 'a', ['text' => 'hello', 'tags' => ['Spoilers']]);
        $this->index(self::BOB, self::THING, 'b', ['text' => 'hi', 'facets' => [['features' => [['tag' => 'spoilers']]]]]);
        $this->index(self::CAROL, self::THING, 'c', ['text' => 'spoilers in the text']);
        $this->index(self::DAVE, self::STATUS, 's', ['status' => '🔥']);

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $this->found(['q' => 'spoilers']));
        $this->assertSame(['a'], $this->found(['q' => 'hello', 'tag' => ['spoilers']]));
        $this->assertSame(['b'], $this->found(['q' => 'hi', 'tag' => ['#Spoilers']]), 'A tag in a facet, given with its #.');
        $this->assertEqualsCanonicalizing(['a', 'b'], $this->found(['tag' => ['spoilers']]), 'A tag alone is a search.');
        $this->assertSame([], $this->found(['q' => 'text', 'tag' => ['spoilers']]), 'The word in text is not a tag.');
        $this->assertSame(['s'], $this->found(['q' => 'flame']), 'Text derived by a SearchText class.');
        $this->assertSame(['s'], $this->found(['q' => 'fire', 'collection' => self::STATUS]));
        $this->assertSame([], $this->found(['q' => 'spoilers', 'collection' => self::STATUS]));
    }

    public function test_results_leave_out_what_the_viewer_should_not_see(): void
    {
        $this->index(self::BOB, self::THING, 'b', ['text' => 'cats']);
        $this->index(self::CAROL, self::THING, 'c', ['text' => 'cats and spoilers']);
        $this->index(self::DAVE, self::THING, 'd', ['text' => 'more cats']);
        app(Graph::class)->mute(self::ALICE, self::BOB);
        app(Graph::class)->muteWord(self::ALICE, 'spoilers');

        $this->assertSame(['d'], $this->found(['q' => 'cats'], self::ALICE));
        $this->assertSame(1, Xrpc::as(self::ALICE)->query('com.example.search.searchRecords', ['q' => 'cats'])['hitsTotal'], 'Counted after filtering, so pages are full.');
        $this->assertSame(['d'], $this->found(['q' => 'cats', 'limit' => 1], self::ALICE));
        $this->assertCount(3, $this->found(['q' => 'cats']));
    }

    public function test_author_and_time_filters(): void
    {
        $this->account(self::BOB, 'bob.test');
        $this->index(self::BOB, self::THING, 'b1', ['text' => 'cats'], '2026-10-20T00:00:00Z');
        $this->index(self::BOB, self::THING, 'b2', ['text' => 'cats'], '2026-10-21T00:00:00Z');
        $this->index(self::CAROL, self::THING, 'c', ['text' => 'cats'], '2026-10-21T00:00:00Z');

        $this->assertSame(['b2', 'b1'], $this->found(['q' => 'cats', 'author' => 'bob.test']));
        $this->assertSame(['b2', 'b1'], $this->found(['q' => 'cats', 'author' => self::BOB]));
        $this->assertSame([], $this->found(['q' => 'cats', 'author' => 'nobody.test']));
        $this->assertSame(['b1'], $this->found(['q' => 'cats', 'until' => '2026-10-20T12:00:00Z']));
        $this->assertSame(['c', 'b2'], $this->found(['q' => 'cats', 'since' => '2026-10-20T12:00:00Z']));
    }

    public function test_top_ranks_recent_matches_by_engagement_follows_and_sources(): void
    {
        $this->index(self::ALICE, self::FOLLOW, 'f1', ['subject' => self::DAVE]);
        $old = $this->index(self::BOB, self::THING, 'old', ['text' => 'cats'], '2026-10-01T00:00:00Z');
        $liked = $this->index(self::BOB, self::THING, 'liked', ['text' => 'cats'], '2026-10-21T06:00:00Z');
        $this->index(self::CAROL, self::THING, 'fresh', ['text' => 'cats'], '2026-10-21T11:00:00Z');
        $this->index(self::DAVE, self::THING, 'followed', ['text' => 'cats'], '2026-10-21T09:00:00Z');

        foreach (range(1, 40) as $i) {
            $this->index("did:plc:fan{$i}", self::LIKE, "l{$i}", ['subject' => ['uri' => $liked]]);
        }
        foreach (range(1, 40) as $i) {
            $this->index("did:plc:fan{$i}", self::LIKE, "o{$i}", ['subject' => ['uri' => $old]]);
        }

        $this->assertSame(['liked', 'fresh', 'followed'], $this->found(['q' => 'cats', 'sort' => 'top'], self::ALICE), 'Likes beat being newest. Older than the window is left out. Follows are not the default ranker\'s.');
        $this->assertSame(['fresh', 'followed', 'liked', 'old'], $this->found(['q' => 'cats', 'sort' => 'latest']));

        $page = Xrpc::as(self::ALICE)->query('com.example.search.searchRecords', ['q' => 'cats', 'sort' => 'top', 'limit' => 2]);
        $this->assertSame('2', $page['cursor']);
        $this->assertSame(['followed'], $this->found(['q' => 'cats', 'sort' => 'top', 'limit' => 2, 'cursor' => '2'], self::ALICE));

        config()->set('atp-appview.search.ranker', FollowedFirst::class);
        $this->assertSame(['followed', 'liked', 'fresh'], $this->found(['q' => 'cats', 'sort' => 'top'], self::ALICE), 'An app\'s ranker can weigh the viewer\'s follows.');
        $this->assertSame(['liked', 'fresh', 'followed'], $this->found(['q' => 'cats', 'sort' => 'top']), 'Signed out, no follows.');
    }

    public function test_the_ranker_sees_what_the_package_looked_up(): void
    {
        $liked = $this->index(self::BOB, self::THING, 'liked', ['text' => 'cats', 'tags' => ['cats']], '2026-10-21T06:00:00Z');
        $this->index(self::FAN, self::LIKE, 'l1', ['subject' => ['uri' => $liked]]);
        $this->index(self::FAN, self::LIKE, 'l2', ['subject' => ['uri' => $liked]]);
        $seen = [];
        app()->instance(RecordingRanker::class, new RecordingRanker($seen));
        config()->set('atp-appview.search.ranker', RecordingRanker::class);

        $this->found(['q' => 'cats', 'sort' => 'top']);

        $this->assertCount(1, $seen);
        $this->assertSame($liked, $seen[0]->uri);
        $this->assertSame(self::BOB, $seen[0]->did);
        $this->assertEqualsCanonicalizing(['text', 'tags'], $seen[0]->matched);
        $this->assertSame([self::LIKE.':subject.uri' => 2], $seen[0]->counts);
        $this->assertSame(6.0, $seen[0]->hours());
    }

    public function test_a_ranker_must_implement_the_contract(): void
    {
        $this->index(self::BOB, self::THING, 'a', ['text' => 'cats']);
        config()->set('atp-appview.search.ranker', \stdClass::class);

        $this->expectException(LogicException::class);
        $this->found(['q' => 'cats', 'sort' => 'top']);
    }

    public function test_top_falls_back_to_the_newest_matches_when_none_are_in_the_window(): void
    {
        $this->index(self::BOB, self::THING, 'older', ['text' => 'cats'], '2026-08-01T00:00:00Z');
        $liked = $this->index(self::CAROL, self::THING, 'liked', ['text' => 'cats'], '2026-07-01T00:00:00Z');

        foreach (range(1, 40) as $i) {
            $this->index("did:plc:fan{$i}", self::LIKE, "l{$i}", ['subject' => ['uri' => $liked]]);
        }

        $this->assertSame(['liked', 'older'], $this->found(['q' => 'cats', 'sort' => 'top']), 'Not empty while Latest has results, and still ranked.');
        $this->assertSame(['older', 'liked'], $this->found(['q' => 'cats', 'sort' => 'latest']));
    }

    public function test_a_query_the_index_cannot_answer_is_refused(): void
    {
        foreach ([['q' => ''], ['q' => '!?'], ['q' => 'cats', 'lang' => 'en'], ['q' => 'cats', 'mentions' => 'bob.test'], ['q' => 'cats', 'collection' => 'com.example.other'], ['q' => 'cats', 'since' => 'soon'], ['q' => 'x', 'collection' => self::STATUS, 'tag' => ['a']]] as $params) {
            try {
                Xrpc::as(null)->query('com.example.search.searchRecords', $params);
                $this->fail('Answered '.json_encode($params));
            } catch (XrpcException $e) {
                $this->assertSame('BadQueryString', $e->error);
            }
        }
    }

    public function test_typeahead_matches_the_start_of_a_handle_or_name_word_followed_first(): void
    {
        $this->account(self::BOB, 'searchlight.test', 'South Dakota Searchlight');
        $this->account(self::CAROL, 'carol.test', 'In search of Ganymede');
        $this->account(self::DAVE, 'researcher.test', 'Dave');
        $this->index(self::ALICE, self::FOLLOW, 'f1', ['subject' => self::CAROL]);

        $handles = fn (array $actors) => array_column($actors, 'handle');
        $typeahead = Xrpc::as(self::ALICE)->query('com.example.search.searchAccountsTypeahead', ['q' => 'sear']);

        $this->assertSame(['carol.test', 'searchlight.test'], $handles($typeahead['actors']), 'Carol is followed. Dave only has it mid-word.');
        $this->assertSame('In search of Ganymede', $typeahead['actors'][0]['displayName']);
        $this->assertTrue($typeahead['actors'][0]['viewer']['following']);
        $this->assertSame(['searchlight.test'], $handles(Xrpc::as(null)->query('com.example.search.searchAccountsTypeahead', ['q' => '@searchl'])['actors']));
    }

    public function test_account_search_needs_every_word_in_handle_name_or_description_and_leaves_out_blocks(): void
    {
        $this->account(self::BOB, 'bob.test', 'Bob', 'Writes about cats and coffee');
        $this->account(self::CAROL, 'carol.test', 'Cats of Carol', 'coffee lover');
        $this->account(self::DAVE, 'dave.test', 'Dave', 'cats only');
        $this->account('did:plc:erin', 'erin.test');

        $handles = fn (array $actors) => array_column($actors, 'handle');

        $this->assertSame(['bob.test', 'carol.test'], $handles(Xrpc::as(null)->query('com.example.search.searchAccounts', ['q' => 'cats coffee'])['actors']));

        $this->index(self::ALICE, self::BLOCK, 'b1', ['subject' => self::BOB]);
        app(Graph::class)->mute(self::ALICE, self::CAROL);
        $actors = Xrpc::as(self::ALICE)->query('com.example.search.searchAccounts', ['q' => 'cats coffee'])['actors'];
        $this->assertSame(['carol.test'], $handles($actors), 'Blocked is left out. Muted stays, marked.');
        $this->assertTrue($actors[0]['viewer']['muted']);

        $first = Xrpc::as(null)->query('com.example.search.searchAccounts', ['q' => 'cats', 'limit' => 2]);
        $this->assertSame('2', $first['cursor']);
        $this->assertCount(1, Xrpc::as(null)->query('com.example.search.searchAccounts', ['q' => 'cats', 'limit' => 2, 'cursor' => '2'])['actors']);
        $this->assertSame(['erin.test'], $handles(Xrpc::as(null)->query('com.example.search.searchAccounts', ['q' => 'erin'])['actors']), 'An account without a profile is found by handle.');
    }

    protected function score(string $did, float $value): void
    {
        DB::table('atp_standing')->upsert([['subject' => $did, 'kind' => Standing::SCORE, 'value' => $value, 'computed_at' => now(), 'stale_at' => now()->addDay()]], ['subject', 'kind'], ['value', 'stale_at']);
    }

    public function test_standing_orders_people_after_follows(): void
    {
        $this->account(self::CAROL, 'cat1.test', 'Cat');
        $this->account(self::DAVE, 'cat2.test', 'Cat');
        $this->account('did:plc:erin', 'cat3.test', 'Cat');
        $this->score(self::DAVE, 40);
        $this->index(self::ALICE, self::FOLLOW, 'f1', ['subject' => 'did:plc:erin']);

        $handles = array_column(Xrpc::as(self::ALICE)->query('com.example.search.searchAccounts', ['q' => 'cat'])['actors'], 'handle');
        $this->assertSame(['cat3.test', 'cat2.test', 'cat1.test'], $handles, 'Followed first, then by standing, then by handle.');
    }

    public function test_without_the_feature_search_refuses(): void
    {
        config()->set('atp-appview.features', []);

        $this->expectException(LogicException::class);
        app(Search::class)->accounts('cats');
    }
}
