<?php

namespace SocialDept\AtpAppview\Tests\Feature\Moderation;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\Events\LabelApplied;
use SocialDept\AtpAppview\Labels\Events\LabelWithdrawn;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Labels\Policies;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpAppview\Models\AccountAction;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Moderation\AccountActions;
use SocialDept\AtpAppview\Moderation\Events\AccountActioned;
use SocialDept\AtpAppview\Tests\TestCase;

class AccountActionsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const POST = 'app.example.post';
    protected const MOD = 'did:plc:mod';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.fields', [self::POST => ['tags' => 'string[]']]);
        $app['config']->set('atp-appview.ingest.admin_url', 'http://tap.test');
        $app['config']->set('atp-appview.pds.hosts', [['driver' => 'admin', 'url' => 'https://pds.example/', 'password' => 'pds-secret']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        DB::table('atp_identities')->insert([
            ['did' => self::ALICE, 'handle' => 'alice.test', 'pds' => 'https://pds.example', 'active' => true, 'updated_at' => now()],
            ['did' => self::BOB, 'handle' => 'bob.test', 'pds' => 'https://elsewhere.example', 'active' => true, 'updated_at' => now()],
        ]);
    }

    protected function indexPost(string $did, string $rkey, string $rev = '3l2aaaaaaaaaa'): void
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, self::POST, $rkey, RecordAction::Create, "bafy-{$rkey}", $rev, ['text' => $rkey, 'tags' => ['php']], true),
        ]));
    }

    protected function actions(): AccountActions
    {
        return app(AccountActions::class);
    }

    public function test_an_install_from_before_moderation_was_a_feature_keeps_its_tables(): void
    {
        app(AccountActions::class)->ban('did:plc:bob', 'spam');

        // Such an install made the tables in core migrations, and meets the
        // moderation feature's own migrations for the first time.
        DB::table('migrations')->where('migration', 'like', '2026_10_26_%')->delete();
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(app(AccountActions::class)->banned('did:plc:bob'), 'Nothing made again, nothing lost.');
    }

    public function test_a_ban_hides_everything_the_account_wrote_and_drops_what_comes_next(): void
    {
        Http::fake();
        $this->indexPost(self::ALICE, 'a');

        $this->actions()->ban(self::ALICE, 'spam', 'did:plc:operator');

        $this->assertSame(0, Record::query()->where('did', self::ALICE)->count());
        $this->assertNotNull(AccountAction::query()->sole()->prune_at);

        $this->indexPost(self::ALICE, 'b');
        $this->assertNull(Record::withHidden()->find('at://did:plc:alice/'.self::POST.'/b'), 'Nothing new is indexed while banned.');

        $this->assertSame(1, $this->actions()->unban(self::ALICE, 'did:plc:operator'));

        $this->assertSame(1, Record::query()->where('did', self::ALICE)->count());
        $this->assertSame('did:plc:operator', AccountAction::query()->sole()->lifted_by);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'tap.test/resync/'.self::ALICE));
    }

    public function test_needs_review_keeps_records_out_of_discovery_but_not_off_the_profile(): void
    {
        $this->indexPost(self::ALICE, 'a');
        $this->indexPost(self::BOB, 'b');

        $this->actions()->review(self::ALICE);

        $this->assertSame(['b'], RecordQuery::in(self::POST)->where('tags', 'php')->get()['records']->pluck('rkey')->all());
        $this->assertSame(['a'], RecordQuery::in(self::POST)->where('tags', 'php')->by(self::ALICE)->get()['records']->pluck('rkey')->all(), 'One repo is asked for directly.');
        $this->assertSame(['b'], Record::query()->discoverable()->pluck('rkey')->all());
        $this->assertSame(2, Record::query()->count(), 'Nothing is hidden.');

        $this->actions()->unreview(self::ALICE);
        $this->assertSame(2, RecordQuery::in(self::POST)->where('tags', 'php')->get()['records']->count());
    }

    public function test_labels_ban_and_review_accounts_and_lift_it_when_withdrawn(): void
    {
        Event::fake([AccountActioned::class, LabelApplied::class, LabelWithdrawn::class]);
        $this->indexPost(self::ALICE, 'a');
        app(Policies::class)->set(self::MOD, 'scam', Target::ACCOUNT, Effect::Ban);

        (new LabelIndexer())->handle(new Batch([new LabelEvent(1, self::MOD, self::ALICE, null, 'scam', false, '2026-01-01T00:00:00.000Z', null, true)]));

        $ban = AccountAction::query()->sole();
        $this->assertSame([AccountAction::BAN, AccountAction::LABEL, self::MOD, 'scam'], [$ban->action, $ban->source, $ban->label_src, $ban->label_val]);
        $this->assertTrue($this->actions()->banned(self::ALICE));
        $this->assertTrue(app(Labels::class)->account(self::ALICE)->ban);
        Event::assertDispatched(LabelApplied::class, fn ($event) => $event->effect === Effect::Ban && $event->target === Target::ACCOUNT);

        (new LabelIndexer())->handle(new Batch([new LabelEvent(2, self::MOD, self::ALICE, null, 'scam', true, '2026-02-01T00:00:00.000Z', null, true)]));

        $this->assertFalse($this->actions()->banned(self::ALICE));
        $this->assertSame('label withdrawn', AccountAction::query()->sole()->lifted_by);
        $this->assertSame(1, Record::query()->count());
        Event::assertDispatched(LabelWithdrawn::class);
        Event::assertDispatched(AccountActioned::class, 2);
    }

    public function test_a_manual_action_outlasts_its_label(): void
    {
        app(Policies::class)->set(self::MOD, 'scam', Target::ACCOUNT, Effect::Ban);
        $this->actions()->ban(self::ALICE, 'by hand');

        (new LabelIndexer())->handle(new Batch([new LabelEvent(1, self::MOD, self::ALICE, null, 'scam', false, '2026-01-01T00:00:00.000Z', null, true)]));
        (new LabelIndexer())->handle(new Batch([new LabelEvent(2, self::MOD, self::ALICE, null, 'scam', true, '2026-02-01T00:00:00.000Z', null, true)]));

        $this->assertTrue($this->actions()->banned(self::ALICE), 'Withdrawing the label lifts only the ban it applied.');
    }

    public function test_a_takedown_reaches_the_pds_the_operator_runs(): void
    {
        Http::fake(['pds.example/*' => Http::response(['takedown' => ['applied' => true]])]);

        $action = $this->actions()->takedown(self::ALICE, 'illegal content', 'did:plc:operator');

        $this->assertSame('https://pds.example', $action->pds);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://pds.example/xrpc/com.atproto.admin.updateSubjectStatus'
            && $request['subject'] === ['$type' => 'com.atproto.admin.defs#repoRef', 'did' => self::ALICE]
            && $request['takedown'] === ['applied' => true, 'ref' => 'atp-appview:'.$action->id]
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('admin:pds-secret')));

        $this->actions()->restore(self::ALICE);
        Http::assertSent(fn (Request $request) => $request['takedown'] === ['applied' => false]);
        $this->assertNotNull($action->fresh()->lifted_at);

        $this->expectException(\RuntimeException::class);
        $this->actions()->takedown(self::BOB);
    }

    public function test_a_bans_records_are_pruned_when_due_unless_cancelled(): void
    {
        $this->indexPost(self::ALICE, 'a');
        $this->indexPost(self::BOB, 'b');
        $this->actions()->ban(self::ALICE);
        $this->actions()->ban(self::BOB);
        $this->actions()->cancelPrune(self::BOB);

        $this->artisan('appview:moderation:prune')->expectsOutputToContain('Pruned 0 records')->assertSuccessful();

        $this->travel(366)->days();
        $this->artisan('appview:moderation:prune')->expectsOutputToContain('Pruned 1 records of 1 banned accounts')->assertSuccessful();

        $this->assertNotNull(Record::withTrashed()->withHidden()->find('at://did:plc:alice/'.self::POST.'/a')->deleted_at);
        $this->assertNull(Record::withHidden()->find('at://did:plc:bob/'.self::POST.'/b')->deleted_at, 'A cancelled pruning keeps them.');
    }
}
