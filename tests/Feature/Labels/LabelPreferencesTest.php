<?php

namespace SocialDept\AtpAppview\Tests\Feature\Labels;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\LabelPreferences;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Labels\Policies;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Views\Parts\Moderation;
use SocialDept\AtpAppview\Views\View;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Each viewer loosening or tightening what labels do for them, within what
 * the operator left to viewers, in queries and in views, signed in by
 * session or by service auth.
 */
class LabelPreferencesTest extends TestCase
{
    protected const MOD = 'did:plc:mod';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const THING = 'com.example.thing';
    protected const SERVICE = 'did:web:appview.example';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('atp-appview.xrpc.collections', ['com.example.listThings' => self::THING]);
        $app['config']->set('atp-appview.xrpc.views', ['com.example.getThings' => PreferredThingView::class]);
        $app['config']->set('atp-appview.fields', [self::THING => ['kind' => 'string']]);
        $app['config']->set('atp-appview.notifications.reasons', [self::THING => ['reply.parent.uri' => 'reply']]);
        $app['config']->set('atp-client.service_auth.audience', self::SERVICE.'#atproto_appview');
        $app['config']->set('auth.providers.users.model', PreferencesUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });

        (new LabelIndexer())->handle(new Batch([new LabelerEvent(1, self::MOD, null, ['policies' => [
            'labelValues' => ['rude', 'spoiler', 'troll', 'kinky'],
            'labelValueDefinitions' => [
                ['identifier' => 'rude', 'severity' => 'alert', 'blurs' => 'none', 'defaultSetting' => 'warn'],
                ['identifier' => 'spoiler', 'severity' => 'none', 'blurs' => 'content', 'defaultSetting' => 'warn'],
                ['identifier' => 'troll', 'severity' => 'alert', 'blurs' => 'none', 'defaultSetting' => 'warn'],
                ['identifier' => 'kinky', 'severity' => 'none', 'blurs' => 'media', 'defaultSetting' => 'warn', 'adultOnly' => true],
            ],
        ]], false)]));
        app(Policies::class)->forget();
    }

    protected function thing(string $did, string $rkey, array $record = []): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, self::THING, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', ['text' => $rkey, 'kind' => 'note', 'createdAt' => '2026-10-01T00:00:00Z', ...$record], true),
        ]));

        return "at://{$did}/".self::THING."/{$rkey}";
    }

    protected function label(string $uri, string $val): void
    {
        (new LabelIndexer())->handle(new Batch([new LabelEvent(++$this->id, self::MOD, $uri, null, $val, false, '2026-01-01T00:00:00.000Z', null, true)]));
    }

    protected function preferences(): LabelPreferences
    {
        return app(LabelPreferences::class);
    }

    /**
     * @return array<int, string>
     */
    protected function visible(?string $viewer): array
    {
        return Record::collection(self::THING)->visibleTo($viewer)->orderBy('uri')->pluck('uri')->all();
    }

    protected function decide(string $uri, ?string $viewer): \SocialDept\AtpAppview\Labels\Decision
    {
        return app(Labels::class)->decide(Record::withoutGlobalScopes()->where('uri', $uri)->sole(), $viewer);
    }

    public function test_a_viewer_loosens_a_warning_or_a_blur_for_themselves_only(): void
    {
        $rude = $this->thing(self::BOB, 'rude');
        $gore = $this->thing(self::BOB, 'gore');
        $this->label($rude, 'rude');
        $this->label($gore, 'graphic-media');

        $this->preferences()->set(self::ALICE, 'rude', 'ignore', labeler: self::MOD);
        $this->preferences()->set(self::ALICE, 'graphic-media', 'show');

        $this->assertSame([], $this->decide($rude, self::ALICE)->warnings);
        $this->assertNull($this->decide($gore, self::ALICE)->blur, "Bluesky's show is ignore.");

        $this->assertSame(['rude'], array_column($this->decide($rude, self::CAROL)->warnings, 'val'), 'Carol chose nothing.');
        $this->assertSame('media', $this->decide($gore, null)->blur);

        $this->assertSame(['adultContent' => true, 'labels' => [
            ['labeler' => null, 'value' => 'graphic-media', 'visibility' => 'ignore'],
            ['labeler' => self::MOD, 'value' => 'rude', 'visibility' => 'ignore'],
        ]], $this->preferences()->for(self::ALICE));

        $this->preferences()->clear(self::ALICE, 'rude', self::MOD);
        $this->assertSame(['rude'], array_column($this->decide($rude, self::ALICE)->warnings, 'val'), 'Cleared, the policy decides again.');
    }

    public function test_a_choice_for_one_labeler_wins_over_one_for_any(): void
    {
        $rude = $this->thing(self::BOB, 'rude');
        $this->label($rude, 'rude');

        $this->preferences()->set(self::ALICE, 'rude', 'hide');
        $this->preferences()->set(self::ALICE, 'rude', 'warn', labeler: self::MOD);

        $this->assertContains($rude, $this->visible(self::ALICE), "The labeler's own choice, warn, wins, so the record stays.");
        $this->assertSame(['rude'], array_column($this->decide($rude, self::ALICE)->warnings, 'val'));
        $this->assertFalse($this->decide($rude, self::ALICE)->hide);
    }

    public function test_an_operators_hide_review_or_ban_cannot_be_loosened(): void
    {
        $porn = $this->thing(self::BOB, 'porn');
        $this->label($porn, 'porn');
        $troll = $this->thing(self::CAROL, 'troll');
        app(Labels::class)->set(self::MOD, 'troll', Target::ACCOUNT, Effect::Ban);
        $this->label(self::CAROL, 'troll');
        $listed = $this->thing(self::BOB, 'listed');
        app(Labels::class)->set(self::MOD, 'rude', Target::CONTENT, Effect::Review);
        $this->label($listed, 'rude');

        foreach (['porn', 'troll', 'rude'] as $value) {
            $this->preferences()->set(self::ALICE, $value, 'ignore');
        }

        $this->assertTrue($this->decide($porn, self::ALICE)->hide);
        $this->assertTrue($this->decide($troll, self::ALICE)->ban);
        $this->assertTrue($this->decide($listed, self::ALICE)->review);
        $this->assertSame([$listed], $this->visible(self::ALICE), 'What the operator hides stays out of her lists.');
    }

    public function test_system_labels_are_not_the_viewers_to_change(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preferences()->set(self::ALICE, '!hide', 'ignore');
    }

    public function test_a_visibility_is_ignore_warn_or_hide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preferences()->set(self::ALICE, 'rude', 'blur');
    }

    public function test_a_viewer_hides_what_the_policy_only_warns_on_wherever_they_read(): void
    {
        $rude = $this->thing(self::BOB, 'rude');
        $fine = $this->thing(self::BOB, 'fine');
        $own = $this->thing(self::ALICE, 'own');
        $this->label($rude, 'rude');
        $this->label($own, 'rude');

        $this->preferences()->set(self::ALICE, 'rude', 'hide');

        $this->assertEqualsCanonicalizing([$fine, $own], $this->visible(self::ALICE), 'Her own labeled record stays.');
        $this->assertCount(3, $this->visible(self::CAROL));
        $this->assertTrue($this->decide($rude, self::ALICE)->hide, 'Asked for by its uri, it says to hide it.');
        $this->assertFalse($this->decide($own, self::ALICE)->hide);

        $filtered = RecordQuery::in(self::THING)->where('kind', 'note')->visibleTo(self::ALICE)->get()['records']->pluck('uri')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$fine, $own], $filtered);

        $listed = array_column(Xrpc::as(self::ALICE)->query('com.example.listThings')['records'], 'uri');
        $this->assertEqualsCanonicalizing([$fine, $own], $listed);
    }

    public function test_hiding_an_account_label_hides_everything_the_account_wrote(): void
    {
        $this->thing(self::BOB, 'one');
        $this->thing(self::BOB, 'two');
        $carols = $this->thing(self::CAROL, 'three');
        $this->label(self::BOB, 'troll');

        $this->preferences()->set(self::ALICE, 'troll', 'hide', labeler: self::MOD);

        $this->assertSame([$carols], $this->visible(self::ALICE));
        $this->assertTrue(app(Labels::class)->account(self::BOB, self::ALICE)->hide);
        $this->assertFalse(app(Labels::class)->account(self::BOB)->hide);
    }

    public function test_notifications_leave_out_what_the_viewer_hides(): void
    {
        $post = $this->thing(self::ALICE, 'post');
        $reply = $this->thing(self::BOB, 'reply', ['reply' => ['parent' => ['uri' => $post]]]);
        $this->label($reply, 'rude');

        $this->assertSame(1, app(Notifications::class)->unreadCount(self::ALICE));

        $this->preferences()->set(self::ALICE, 'rude', 'hide');
        $this->assertSame(0, app(Notifications::class)->unreadCount(self::ALICE));
    }

    public function test_adult_content_off_hides_adult_labels_whatever_was_chosen(): void
    {
        $sexual = $this->thing(self::BOB, 'sexual');
        $kinky = $this->thing(self::BOB, 'kinky');
        $nudity = $this->thing(self::BOB, 'nudity');
        $this->label($sexual, 'sexual');
        $this->label($kinky, 'kinky');
        $this->label($nudity, 'nudity');

        $this->preferences()->set(self::ALICE, 'sexual', 'ignore');
        $this->assertNull($this->decide($sexual, self::ALICE)->blur);

        $this->preferences()->setAdultContent(self::ALICE, false);

        $this->assertSame([$nudity], $this->visible(self::ALICE), "Bluesky's sexual and a declared adult-only value hide, nudity does not.");
        $this->assertTrue($this->decide($sexual, self::ALICE)->hide);
        $this->assertFalse($this->preferences()->for(self::ALICE)['adultContent']);
        $this->assertCount(3, $this->visible(self::CAROL));

        config(['atp-appview.labels.adult_content' => false]);
        $this->app->forgetScopedInstances();
        $this->assertSame([$nudity], $this->visible(self::CAROL), 'The default for viewers who chose nothing.');
        $this->assertCount(3, $this->visible(null), 'Signed out, the policies decide.');
    }

    public function test_preferences_are_read_once_for_a_page(): void
    {
        foreach (range(1, 5) as $i) {
            $this->label($this->thing(self::BOB, "t{$i}"), 'rude');
        }
        $this->preferences()->set(self::ALICE, 'rude', 'ignore');
        $this->app->forgetScopedInstances();

        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            $reads += (int) str_contains($query->sql, 'atp_label_preferences');
        });

        $this->getJson('/xrpc/com.example.getThings?limit=5', $this->bearer('com.example.getThings'))->assertOk();

        $this->assertSame(1, $reads);
    }

    public function test_preferences_apply_signed_in_by_session(): void
    {
        $user = PreferencesUser::create(['did' => self::ALICE]);
        [$rude, $gore] = $this->labeledPage();

        $things = $this->actingAs($user, 'web')->getJson('/xrpc/com.example.getThings')->assertOk()->json('things');

        $this->assertSame([$gore], array_column($things, 'uri'));
        $this->assertSame([], $things[0]['moderation']);
    }

    public function test_preferences_apply_signed_in_by_service_auth(): void
    {
        [$rude, $gore] = $this->labeledPage();

        $things = $this->getJson('/xrpc/com.example.getThings', $this->bearer('com.example.getThings'))->assertOk()->json('things');

        $this->assertSame([$gore], array_column($things, 'uri'));
        $this->assertSame([], $things[0]['moderation']);
    }

    /**
     * Alice hides rude and ignores graphic media.
     *
     * @return array{0: string, 1: string}
     */
    protected function labeledPage(): array
    {
        $rude = $this->thing(self::BOB, 'rude');
        $gore = $this->thing(self::BOB, 'gore');
        $this->label($rude, 'rude');
        $this->label($gore, 'graphic-media');
        $this->preferences()->set(self::ALICE, 'rude', 'hide');
        $this->preferences()->set(self::ALICE, 'graphic-media', 'ignore');

        return [$rude, $gore];
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $method): array
    {
        $this->mock(ServiceAuth::class, function ($mock) use ($method) {
            $mock->shouldReceive('fromHeader')->andReturn('jwt');
            $mock->shouldReceive('verify')->andReturn(new ServiceAuthToken(self::ALICE, self::SERVICE, $method, time() + 60));
        });

        return ['Authorization' => 'Bearer jwt'];
    }
}

class PreferredThingView extends View
{
    public function collection(): string
    {
        return 'com.example.thing';
    }

    public function parts(): array
    {
        return ['moderation' => new Moderation()];
    }
}

class PreferencesUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
