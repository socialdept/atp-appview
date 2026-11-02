<?php

namespace SocialDept\AtpAppview\Tests\Feature\Claims;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Claims\Claims;
use SocialDept\AtpAppview\Claims\RefreshClaims;
use SocialDept\AtpAppview\Claims\Verdict;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\Fixtures\Claims\SiteChecker;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Claims: verdicts stored per record, kept through failures inside the grace
 * window, reset when a claimed field changes, and inherited from a trusted
 * parent in the same repo, resolved at read time.
 */
class ClaimsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const SITE = 'com.example.site';
    protected const POST = 'com.example.post';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.ingest.collections', [self::SITE, self::POST]);
        $app['config']->set('atp-appview.features', [Features::claims()]);
        $app['config']->set('atp-appview.claims.collections', [
            self::SITE => ['checker' => SiteChecker::class],
            self::POST => [
                'checker' => SiteChecker::class,
                'inherit' => ['via' => 'site', 'trust_after' => 2, 'sample_rate' => 0.1, 'sample_every' => 604800],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        SiteChecker::$verdicts = [];
        SiteChecker::$checked = [];
        Lottery::alwaysLose();
        $this->artisan('migrate')->run();
        $this->travelTo('2026-11-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Lottery::determineResultNormally();

        parent::tearDown();
    }

    protected function index(string $did, string $collection, string $rkey, array $record, RecordAction $action = RecordAction::Create): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, $action, $action === RecordAction::Delete ? null : "bafy-{$did}-{$rkey}-{$this->id}", '3l2aaaaaaaaaa', $action === RecordAction::Delete ? null : $record + ['createdAt' => '2026-10-20T00:00:00Z'], true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function claim(string $uri): ?object
    {
        return DB::table(Claims::TABLE)->where('uri', $uri)->first();
    }

    /**
     * @return array<int, string>
     */
    protected function verified(): array
    {
        return Record::query()->verified()->orderBy('uri')->pluck('uri')->all();
    }

    protected function check(string ...$uris): void
    {
        app(Claims::class)->refresh($uris);
    }

    /**
     * A site verified directly, passing this many checks in a row.
     */
    protected function trustedSite(int $passes = 2, string $did = self::ALICE): string
    {
        $site = $this->index($did, self::SITE, 'self', ['url' => 'https://alice.example']);
        SiteChecker::$verdicts[$site] = Verdict::verified();

        for ($i = 0; $i < $passes; $i++) {
            $this->check($site);
        }

        return $site;
    }

    public function test_a_new_claim_waits_as_pending_and_a_check_verifies_it(): void
    {
        $site = $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://alice.example']);
        $this->index(self::BOB, self::SITE, 'self', ['name' => 'no claim']);

        $claim = $this->claim($site);
        $this->assertSame(Claims::PENDING, $claim->state);
        $this->assertSame('alice.example', $claim->host);
        $this->assertSame(1, DB::table(Claims::TABLE)->count(), 'A record making no claim has no row.');
        $this->assertSame([], $this->verified());

        SiteChecker::$verdicts[$site] = Verdict::verified();
        $this->check($site);

        $this->assertSame(Claims::VERIFIED, $this->claim($site)->state);
        $this->assertSame(Claims::DIRECT, $this->claim($site)->method);
        $this->assertSame([$site], $this->verified());
    }

    public function test_failed_checks_never_demote_inside_the_grace_window(): void
    {
        $site = $this->trustedSite(1);

        // Two failures of each kind, over two days: inside both limits.
        SiteChecker::$verdicts[$site] = Verdict::unknown('Timed out');
        $this->check($site);
        $this->travel(1)->days();
        SiteChecker::$verdicts[$site] = Verdict::unverified('No proof');
        $this->check($site);
        $this->travel(1)->days();
        $this->check($site);

        $this->assertSame([$site], $this->verified(), 'Three failures inside 72 hours keep it.');
        $this->assertSame(3, (int) $this->claim($site)->failures);
        $this->assertSame('No proof', $this->claim($site)->last_error);

        $this->travel(1)->days();
        SiteChecker::$verdicts[$site] = Verdict::unknown('Timed out');
        $this->check($site);

        $this->assertSame([], $this->verified(), 'Past both limits, it is demoted.');
        $this->assertSame(Claims::UNVERIFIED, $this->claim($site)->state);
    }

    public function test_a_transient_failure_backs_off_and_a_pass_clears_it(): void
    {
        $site = $this->trustedSite(1);
        SiteChecker::$verdicts[$site] = Verdict::unknown('Down');

        $this->check($site);
        $this->assertSame('2026-11-01 12:15:00', $this->claim($site)->next_check_at);
        $this->check($site);
        $this->assertSame('2026-11-01 13:00:00', $this->claim($site)->next_check_at);

        SiteChecker::$verdicts[$site] = Verdict::verified();
        $this->check($site);

        $this->assertSame(0, (int) $this->claim($site)->failures);
        $this->assertNull($this->claim($site)->failing_since);
        $this->assertSame('2026-11-02 12:00:00', $this->claim($site)->next_check_at);
    }

    public function test_a_record_never_verified_fails_at_once(): void
    {
        $site = $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://alice.example']);

        SiteChecker::$verdicts[$site] = Verdict::unknown();
        $this->check($site);
        $this->assertSame(Claims::PENDING, $this->claim($site)->state, 'Unknown leaves it pending.');

        SiteChecker::$verdicts[$site] = Verdict::unverified();
        $this->check($site);
        $this->assertSame(Claims::UNVERIFIED, $this->claim($site)->state);
    }

    public function test_a_single_unverified_recheck_repeats_however_often_it_fails(): void
    {
        $site = $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://alice.example']);
        SiteChecker::$verdicts[$site] = Verdict::unverified('No proof');

        $this->check($site);
        $this->assertSame('2026-11-01 18:00:00', $this->claim($site)->next_check_at);

        for ($i = 0; $i < 5; $i++) {
            $this->check($site);
        }

        $this->assertSame(6, (int) $this->claim($site)->failures);
        $this->assertSame('2026-11-01 18:00:00', $this->claim($site)->next_check_at);
    }

    public function test_an_unverified_recheck_ladder_grows_with_failures_and_settles_on_its_last_step(): void
    {
        config()->set('atp-appview.claims.recheck.unverified', [21600, 86400, 604800]);
        $site = $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://alice.example']);
        SiteChecker::$verdicts[$site] = Verdict::unverified('No proof');

        $waits = [];

        for ($i = 0; $i < 4; $i++) {
            $this->check($site);
            $waits[] = $this->claim($site)->next_check_at;
        }

        $this->assertSame(['2026-11-01 18:00:00', '2026-11-02 12:00:00', '2026-11-08 12:00:00', '2026-11-08 12:00:00'], $waits);
    }

    public function test_a_pass_or_a_claim_change_returns_the_ladder_to_its_first_step(): void
    {
        config()->set('atp-appview.claims.recheck.unverified', [21600, 86400, 604800]);
        // No grace, so the first failure after a pass demotes at once.
        config()->set('atp-appview.claims.grace', ['failures' => 1, 'seconds' => 0]);
        $site = $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://alice.example']);
        SiteChecker::$verdicts[$site] = Verdict::unverified('No proof');
        $this->check($site);
        $this->check($site);

        SiteChecker::$verdicts[$site] = Verdict::verified();
        $this->check($site);
        SiteChecker::$verdicts[$site] = Verdict::unverified('No proof');
        $this->check($site);
        $this->assertSame('2026-11-01 18:00:00', $this->claim($site)->next_check_at, 'A pass starts it again.');

        $this->check($site);
        $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://elsewhere.example'], RecordAction::Update);
        $this->check($site);
        $this->assertSame('2026-11-01 18:00:00', $this->claim($site)->next_check_at, 'A claim change starts it again.');
    }

    public function test_a_record_losing_its_verdict_starts_the_ladder_at_its_first_step(): void
    {
        config()->set('atp-appview.claims.recheck.unverified', [21600, 86400, 604800]);
        $site = $this->trustedSite(1);
        SiteChecker::$verdicts[$site] = Verdict::unverified('No proof');

        $this->check($site);
        $this->travel(2)->days();
        $this->check($site);
        $this->travel(2)->days();
        $this->check($site);

        $this->assertSame(Claims::UNVERIFIED, $this->claim($site)->state);
        $this->assertSame(1, (int) $this->claim($site)->failures);
        $this->assertSame('2026-11-05 18:00:00', $this->claim($site)->next_check_at);

        $this->check($site);
        $this->assertSame('2026-11-06 12:00:00', $this->claim($site)->next_check_at);
    }

    public function test_changing_a_claimed_field_resets_the_verdict_and_other_fields_keep_it(): void
    {
        $site = $this->trustedSite(1);

        $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://alice.example', 'name' => 'Renamed'], RecordAction::Update);
        $this->assertSame(Claims::VERIFIED, $this->claim($site)->state);
        $this->assertSame([$site], $this->verified());

        $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://elsewhere.example'], RecordAction::Update);
        $this->assertSame(Claims::PENDING, $this->claim($site)->state);
        $this->assertSame('elsewhere.example', $this->claim($site)->host);
        $this->assertSame([], $this->verified());
    }

    public function test_deleting_a_record_or_dropping_its_claim_forgets_the_verdict(): void
    {
        $site = $this->trustedSite(1);
        $this->index(self::ALICE, self::SITE, 'self', ['name' => 'no url'], RecordAction::Update);
        $this->assertNull($this->claim($site));

        $other = $this->index(self::BOB, self::SITE, 'self', ['url' => 'https://bob.example']);
        $this->index(self::BOB, self::SITE, 'self', [], RecordAction::Delete);
        $this->assertNull($this->claim($other));
    }

    public function test_a_record_inherits_from_a_trusted_parent_in_the_same_repo(): void
    {
        $site = $this->trustedSite(1);
        $post = $this->index(self::ALICE, self::POST, 'p1', ['url' => 'https://alice.example/p1', 'site' => $site]);
        $foreign = $this->index(self::BOB, self::POST, 'p2', ['url' => 'https://alice.example/p2', 'site' => $site]);

        $this->assertSame($site, $this->claim($post)->parent);
        $this->assertNull($this->claim($foreign)->parent, 'A parent in another repo is never one.');
        $this->assertSame([$site], $this->verified(), 'One pass is short of trust_after.');

        SiteChecker::$verdicts[$site] = Verdict::verified();
        $this->check($site);

        $this->assertSame([$post, $site], $this->verified(), 'Trusted, the parent lends its verdict before any check.');
        $this->assertSame(Claims::PENDING, $this->claim($post)->state);

        SiteChecker::$checked = [];
        $this->check($post, $foreign);
        $this->assertSame(Claims::INHERITED, $this->claim($post)->method);
        $this->assertSame([$foreign], SiteChecker::$checked, 'Only the record that cannot inherit is checked.');
    }

    public function test_a_parent_whose_claim_changed_lends_nothing(): void
    {
        $site = $this->trustedSite();
        $post = $this->index(self::ALICE, self::POST, 'p1', ['url' => 'https://alice.example/p1', 'site' => $site]);
        $this->assertContains($post, $this->verified());

        $this->index(self::ALICE, self::SITE, 'self', ['url' => 'https://changed.example'], RecordAction::Update);

        $this->assertSame([], $this->verified());
    }

    public function test_a_parent_losing_its_verdict_takes_its_records_with_no_job(): void
    {
        $site = $this->trustedSite();
        $post = $this->index(self::ALICE, self::POST, 'p1', ['url' => 'https://alice.example/p1', 'site' => $site]);
        $this->check($post);
        $this->assertSame([$post, $site], $this->verified());

        Queue::fake();
        DB::table(Claims::TABLE)->where('uri', $site)->update(['state' => Claims::UNVERIFIED]);

        $this->assertSame([], $this->verified());
        Queue::assertNothingPushed();
    }

    public function test_a_failed_sample_revokes_inheritance_until_the_parent_earns_trust_again(): void
    {
        $site = $this->trustedSite();
        $p1 = $this->index(self::ALICE, self::POST, 'p1', ['url' => 'https://alice.example/p1', 'site' => $site]);
        $p2 = $this->index(self::ALICE, self::POST, 'p2', ['url' => 'https://alice.example/p2', 'site' => $site]);
        $this->assertSame([$p1, $p2, $site], $this->verified());

        Lottery::alwaysWin();
        SiteChecker::$verdicts[$p1] = Verdict::unverified('Not on the site');
        $this->check($p1);

        $this->assertSame(Claims::SAMPLED, $this->claim($p1)->method);
        $this->assertSame([$site], $this->verified(), 'Its sibling stops inheriting too.');

        Lottery::alwaysLose();
        $this->check($site);
        $this->assertSame([$site], $this->verified(), 'One pass is short of trust_after.');
        $this->check($site);
        $this->assertSame([$p2, $site], $this->verified(), 'The failed sample stays out on its own verdict.');
    }

    public function test_a_passed_sample_is_verified_on_its_own(): void
    {
        $site = $this->trustedSite();
        $post = $this->index(self::ALICE, self::POST, 'p1', ['url' => 'https://alice.example/p1', 'site' => $site]);

        Lottery::alwaysWin();
        SiteChecker::$verdicts[$post] = Verdict::verified();
        $this->check($post);

        $this->assertSame(Claims::SAMPLED, $this->claim($post)->method);
        DB::table(Claims::TABLE)->where('uri', $site)->update(['state' => Claims::UNVERIFIED]);
        $this->assertSame([$post], $this->verified());
    }

    public function test_a_refresh_writes_in_a_constant_number_of_queries(): void
    {
        $site = $this->trustedSite();
        $posts = [];

        for ($i = 0; $i < 30; $i++) {
            $posts[] = $this->index(self::ALICE, self::POST, "p{$i}", ['url' => "https://alice.example/p{$i}", 'site' => $site]);
        }

        $others = [];

        for ($i = 0; $i < 30; $i++) {
            $others[] = $other = $this->index(self::BOB, self::SITE, "s{$i}", ['url' => "https://bob{$i}.example"]);
            SiteChecker::$verdicts[$other] = Verdict::verified();
        }

        DB::enableQueryLog();
        $this->check(...$posts, ...$others);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(30, DB::table(Claims::TABLE)->where('method', Claims::INHERITED)->count());
        $this->assertSame(30, DB::table(Claims::TABLE)->whereIn('uri', $others)->where('passes', 1)->count());
        $this->assertLessThanOrEqual(8, $queries, 'Three reads, then one update per set of identical values, never one per record.');
    }

    public function test_the_refresh_queues_one_job_per_host_and_respects_its_limit(): void
    {
        config(['atp-appview.claims.per_host_per_minute' => 2]);
        $a = $this->index(self::ALICE, self::SITE, 'a', ['url' => 'https://one.example/a']);
        $b = $this->index(self::ALICE, self::SITE, 'b', ['url' => 'https://one.example/b']);
        $c = $this->index(self::ALICE, self::SITE, 'c', ['url' => 'https://one.example/c']);
        $d = $this->index(self::BOB, self::SITE, 'd', ['url' => 'https://two.example/d']);

        Queue::fake();
        $this->artisan('appview:claims:refresh --stale')->assertSuccessful();

        Queue::assertPushed(RefreshClaims::class, 2);
        Queue::assertPushed(RefreshClaims::class, fn (RefreshClaims $job) => $job->host === 'one.example' && $job->uris === [$a, $b, $c]);

        $this->check($a, $b, $c, $d);

        $this->assertCount(3, SiteChecker::$checked, 'Two from one.example, then its limit, and one from two.example.');
        $this->assertSame([$c], app(Claims::class)->due(), 'The record past the limit stays due.');
    }
}
