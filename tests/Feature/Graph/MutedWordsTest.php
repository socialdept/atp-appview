<?php

namespace SocialDept\AtpAppview\Tests\Feature\Graph;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\Field;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpAppview\Views\Parts\MutedWords;
use SocialDept\AtpAppview\Views\View;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Muted words: the words of each configured source kept in the index, and
 * records holding all the words of a viewer's muted entry left out wherever
 * that viewer reads.
 */
class MutedWordsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const THING = 'com.example.thing';
    protected const FOLLOW = 'com.example.follow';

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
        $app['config']->set('atp-appview.graph.blocks', null);
        $app['config']->set('atp-appview.graph.muted_words', [self::THING => [
            'text' => ['text'],
            'tags' => ['tags', 'facets.*.features.*.tag'],
        ]]);
        $app['config']->set('atp-appview.notifications.reasons', [self::THING => ['reply.parent.uri' => 'reply']]);
        $app['config']->set('atp-appview.fields', [self::THING => ['kind' => 'string']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    protected function index(string $did, string $rkey, array $record, string $collection = self::THING): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', $record + ['kind' => 'note', 'createdAt' => '2026-10-01T00:00:00Z'], true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function graph(): Graph
    {
        return app(Graph::class);
    }

    /**
     * @return array<int, string>
     */
    protected function visible(string $viewer): array
    {
        return Record::collection(self::THING)->visibleTo($viewer)->orderBy('rkey')->pluck('rkey')->all();
    }

    protected function posts(): void
    {
        $this->index(self::BOB, 'a', ['text' => 'The protest downtown got Violent today']);
        $this->index(self::BOB, 'b', ['text' => 'Lovely day for a picnic', 'tags' => ['Spoilers']]);
        $this->index(self::CAROL, 'c', ['text' => 'No tag here, just spoilers in words']);
        $this->index(self::CAROL, 'd', ['text' => 'Facet tagged', 'facets' => [['features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'spoilers']]]]]);
        $this->index(self::CAROL, 'e', ['text' => 'violent storms, no protest 🔥']);
    }

    public function test_words_are_split_case_folded_and_emoji_kept_whole(): void
    {
        $this->assertSame(['the', 'café', 'is', 'open', '🔥', '👍🏽', '🧑‍🚀', '🇯🇵', 'don', 't'], Field::words("The CAFÉ is open! 🔥 👍🏽 🧑‍🚀 🇯🇵 don't"));
        $this->assertSame(['hi'], Field::words('hi hi HI'));
    }

    public function test_each_source_path_is_kept_as_an_indexed_word_field(): void
    {
        $this->posts();
        $values = app(RecordValues::class);

        $field = $values->field(self::THING, Graph::mutedWordField('tags', 1));
        $this->assertNotNull($field);
        $this->assertTrue($field->words);
        $this->assertSame('facets.*.features.*.tag', $field->path);

        $words = fn (string $rkey, string $source, int $path) => array_column(
            $values->field(self::THING, Graph::mutedWordField($source, $path))->values(Record::query()->where('rkey', $rkey)->firstOrFail()->record),
            'text',
        );

        $this->assertSame(['the', 'protest', 'downtown', 'got', 'violent', 'today'], $words('a', 'text', 0));
        $this->assertSame(['spoilers'], $words('b', 'tags', 0));
        $this->assertSame(['spoilers'], $words('d', 'tags', 1));
    }

    public function test_a_muted_word_hides_records_holding_it_from_that_viewer_only(): void
    {
        $this->posts();

        $this->graph()->muteWord(self::ALICE, 'VIOLENT');

        $this->assertSame(['b', 'c', 'd'], $this->visible(self::ALICE));
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $this->visible(self::BOB));
        $this->assertCount(5, Record::collection(self::THING)->visibleTo(null)->get());
    }

    public function test_a_phrase_hides_only_records_holding_every_word_of_it(): void
    {
        $this->posts();

        $this->graph()->muteWord(self::ALICE, 'violent protest');

        // a and e both hold the two words, in any order.
        $this->assertSame(['b', 'c', 'd'], $this->visible(self::ALICE));

        $this->graph()->muteWord(self::BOB, 'violent picnic');
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $this->visible(self::BOB));
    }

    public function test_an_entry_limited_to_a_source_ignores_the_others(): void
    {
        $this->posts();

        $this->graph()->muteWord(self::ALICE, '#spoilers', ['tags']);
        $this->assertSame(['a', 'c', 'e'], $this->visible(self::ALICE), 'Tags and facet tags, not the word in the text.');

        $this->graph()->muteWord(self::BOB, 'spoilers');
        $this->assertSame(['a', 'e'], $this->visible(self::BOB), 'Every source when none is named.');
    }

    public function test_an_entry_stops_applying_once_it_expires_or_is_removed(): void
    {
        $this->posts();
        Carbon::setTestNow('2026-10-20 12:00:00');

        $id = $this->graph()->muteWord(self::ALICE, 'violent', until: Carbon::parse('2026-10-21 12:00:00'));
        $this->assertSame(['b', 'c', 'd'], $this->visible(self::ALICE));
        $this->assertSame([['id' => $id, 'value' => 'violent', 'words' => ['violent'], 'sources' => null, 'excludeFollowing' => false, 'expiresAt' => '2026-10-21T12:00:00Z']], $this->graph()->mutedWords(self::ALICE));

        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $this->visible(self::ALICE));
        $this->assertSame([], $this->graph()->mutedWords(self::ALICE));

        $other = $this->graph()->muteWord(self::ALICE, 'picnic');
        $this->graph()->unmuteWord(self::BOB, $other);
        $this->assertSame(['a', 'c', 'd', 'e'], $this->visible(self::ALICE), 'Only its own account can remove it.');

        $this->graph()->unmuteWord(self::ALICE, $other);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $this->visible(self::ALICE));
    }

    public function test_an_entry_can_excuse_accounts_the_viewer_follows(): void
    {
        $this->posts();
        $this->index(self::ALICE, 'f1', ['subject' => self::BOB], self::FOLLOW);

        $this->graph()->muteWord(self::ALICE, 'violent', excludeFollowing: true);

        $this->assertSame(['a', 'b', 'c', 'd'], $this->visible(self::ALICE), 'Bob is followed, Carol is not.');
    }

    public function test_an_entry_needs_a_word(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->graph()->muteWord(self::ALICE, ' #!? ');
    }

    public function test_indexed_queries_the_xrpc_list_and_notifications_leave_muted_records_out(): void
    {
        $this->posts();
        $post = $this->index(self::ALICE, 'post', ['text' => 'hello']);
        $this->index(self::BOB, 'r1', ['text' => 'such a violent reply', 'reply' => ['parent' => ['uri' => $post]]]);
        $this->index(self::CAROL, 'r2', ['text' => 'a kind reply', 'reply' => ['parent' => ['uri' => $post]]]);
        $this->assertSame(2, app(Notifications::class)->unreadCount(self::ALICE));

        $this->graph()->muteWord(self::ALICE, 'violent');

        $this->assertSame(1, app(Notifications::class)->unreadCount(self::ALICE));

        $rkeys = fn ($records) => collect($records)->map(fn ($record) => is_array($record) ? explode('/', $record['uri'])[4] : $record->rkey)->sort()->values()->all();

        $this->assertSame(['b', 'c', 'd', 'post', 'r2'], $rkeys(RecordQuery::in(self::THING)->where('kind', 'note')->visibleTo(self::ALICE)->get()['records']));
        $this->assertSame(['b', 'c', 'd', 'post', 'r2'], $rkeys(Xrpc::as(self::ALICE)->query('com.example.listThings', ['limit' => 5])['records']));
    }

    public function test_a_view_part_names_the_muted_words_a_record_holds_instead_of_hiding_it(): void
    {
        $this->posts();
        $this->index(self::ALICE, 'mine', ['text' => 'my violent protest']);
        $this->index(self::ALICE, 'f1', ['subject' => self::CAROL], self::FOLLOW);
        $this->graph()->muteWord(self::ALICE, 'violent picnic');
        $this->graph()->muteWord(self::ALICE, 'violent');
        $this->graph()->muteWord(self::ALICE, 'protest violent');
        $this->graph()->muteWord(self::ALICE, '#spoilers', ['tags']);
        $this->graph()->muteWord(self::ALICE, 'storms', excludeFollowing: true);

        $view = new class () extends View {
            public function collection(): string
            {
                return 'com.example.thing';
            }

            public function parts(): array
            {
                return ['muted' => new MutedWords()];
            }
        };

        $records = Record::query()->where('collection', self::THING)->orderBy('rkey')->get();
        $muted = collect(app(Hydrator::class)->hydrate($view, $records, self::ALICE))->mapWithKeys(fn (array $view) => [explode('/', $view['uri'])[4] => $view['muted']])->all();

        $this->assertSame([
            'a' => ['protest violent', 'violent'],
            'b' => ['#spoilers'],
            'c' => null,
            'd' => ['#spoilers'],
            'e' => ['protest violent', 'violent'],
            'mine' => null,
        ], $muted, 'Newest entry first. No record holds both words of violent picnic. Storms spares Carol, whom Alice follows, and her own records are never muted.');
        $this->assertNull(app(Hydrator::class)->hydrate($view, $records, null)[0]['muted'], 'Not without a viewer.');
    }

    public function test_text_that_is_not_a_record_can_be_checked_against_muted_words(): void
    {
        $this->graph()->muteWord(self::ALICE, 'violent protest');
        $this->graph()->muteWord(self::ALICE, '#spoilers', ['tags']);

        $this->assertSame(['violent protest'], $this->graph()->mutedWordsInText(self::ALICE, 'Protest turns VIOLENT'));
        $this->assertSame([], $this->graph()->mutedWordsInText(self::ALICE, 'violent storms'));
        $this->assertSame(['#spoilers'], $this->graph()->mutedWordsInText(self::ALICE, 'spoilers', 'tags'));
        $this->assertSame([], $this->graph()->mutedWordsInText(self::ALICE, 'spoilers', 'text'));
        $this->assertSame([], $this->graph()->mutedWordsInText(self::BOB, 'violent protest'));
    }
}
