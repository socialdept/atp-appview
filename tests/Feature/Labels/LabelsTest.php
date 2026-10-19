<?php

namespace SocialDept\AtpAppview\Tests\Feature\Labels;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\AccountStatus;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\Policies;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Record;

class LabelsTest extends TestCase
{
    protected const MOD = 'did:plc:mod';
    protected const ALICE = 'did:plc:alice';
    protected const POST = 'app.example.post';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.fields', [self::POST => ['tags' => 'string[]']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        DB::table('atp_identities')->insert(['did' => self::ALICE, 'handle' => 'alice.test', 'active' => true, 'updated_at' => now()]);
    }

    protected function indexPost(string $rkey, array $record = [], string $rev = '3l2aaaaaaaaaa'): void
    {
        (new RecordIndexer(app('db')))->handle(new Batch([
            new RecordEvent(++$this->id, self::ALICE, self::POST, $rkey, RecordAction::Create, "bafy-{$rkey}-{$rev}", $rev, ['text' => $rkey, 'tags' => ['php'], ...$record], true),
        ]));
    }

    protected function label(string $uri, string $val, string $cts = '2026-01-01T00:00:00.000Z', bool $neg = false, string $src = self::MOD): void
    {
        (new LabelIndexer())->handle(new Batch([new LabelEvent(++$this->id, $src, $uri, null, $val, $neg, $cts, null, true)]));
    }

    protected function uri(string $rkey): string
    {
        return 'at://'.self::ALICE.'/'.self::POST.'/'.$rkey;
    }

    protected function phpCount(): int
    {
        return (int) DB::table('atp_value_counts')->where('value', 'php')->value('total');
    }

    protected function policy(string $val, string $target, string $effect): void
    {
        app(Policies::class)->set(self::MOD, $val, $target, Effect::from($effect));
    }

    public function test_the_newest_label_wins_and_a_withdrawal_only_removes_an_older_one(): void
    {
        $this->label(self::ALICE, 'spam', '2026-02-01T00:00:00.000Z');
        $this->label(self::ALICE, 'spam', '2026-01-01T00:00:00.000Z');
        $this->assertSame('2026-02-01T00:00:00.000Z', Label::query()->sole()->cts, 'An older label changes nothing.');

        $this->label(self::ALICE, 'spam', '2026-01-15T00:00:00.000Z', neg: true);
        $this->assertSame(1, Label::query()->count(), 'A withdrawal older than the label leaves it.');

        $this->label(self::ALICE, 'spam', '2026-03-01T00:00:00.000Z', neg: true);
        $this->assertSame(0, Label::query()->count());
    }

    public function test_a_labelers_declaration_is_kept_and_removing_it_takes_its_labels(): void
    {
        (new LabelIndexer())->handle(new Batch([new LabelerEvent(1, self::MOD, 'https://mod.example', ['policies' => ['labelValues' => ['spam']]], false)]));
        $this->label(self::ALICE, 'spam');

        $this->assertSame(['spam'], \SocialDept\AtpAppview\Models\Labeler::query()->sole()->values());

        (new LabelIndexer())->handle(new Batch([new LabelerEvent(2, self::MOD, null, null, true)]));

        $this->assertSame(0, \SocialDept\AtpAppview\Models\Labeler::query()->count());
        $this->assertSame(0, Label::query()->count());
    }

    public function test_what_a_label_does_comes_from_the_operator_then_config_then_the_declaration(): void
    {
        $policies = app(Policies::class);

        $this->assertSame(Effect::Ignore, $policies->effect(self::MOD, 'unknown', Target::CONTENT), 'An undefined value does nothing.');
        $this->assertSame(Effect::Hide, $policies->effect(self::MOD, 'porn', Target::CONTENT), "Bluesky's own values have defaults.");
        $this->assertSame('media', $policies->blurs(self::MOD, 'graphic-media'));
        $this->assertSame(Effect::Hide, $policies->effect(self::MOD, '!hide', Target::ACCOUNT));
        $this->assertSame(Effect::Review, $policies->effect(self::MOD, '!no-promote', Target::CONTENT));

        (new LabelIndexer())->handle(new Batch([new LabelerEvent(1, self::MOD, null, ['policies' => [
            'labelValues' => ['rude', 'spoiler'],
            'labelValueDefinitions' => [
                ['identifier' => 'rude', 'severity' => 'alert', 'blurs' => 'none', 'defaultSetting' => 'warn'],
                ['identifier' => 'spoiler', 'severity' => 'none', 'blurs' => 'content', 'defaultSetting' => 'warn'],
            ],
        ]], false)]));
        $policies->forget();

        $this->assertSame(Effect::Warn, $policies->effect(self::MOD, 'rude', Target::CONTENT));
        $this->assertSame(Effect::Blur, $policies->effect(self::MOD, 'spoiler', Target::CONTENT));
        $this->assertSame('content', $policies->blurs(self::MOD, 'spoiler'));

        config(['atp-appview.labels.policies' => ['*' => ['rude' => ['content' => 'badge']]]]);
        $this->assertSame(Effect::Badge, $policies->effect(self::MOD, 'rude', Target::CONTENT), 'Config overrides the declaration.');

        $this->policy('rude', Target::CONTENT, 'hide');
        $this->assertSame(Effect::Hide, $policies->effect(self::MOD, 'rude', Target::CONTENT), 'The operator overrides config.');

        $this->policy('rude', Target::CONTENT, 'ban');
        $this->assertSame(Effect::Hide, $policies->effect(self::MOD, 'rude', Target::CONTENT), 'A ban on content hides it.');
    }

    public function test_a_hiding_content_label_takes_the_record_out_of_lists_and_counts_until_withdrawn(): void
    {
        $this->indexPost('a');
        $this->indexPost('b');
        $this->assertSame(2, $this->phpCount());

        $this->label($this->uri('a'), 'porn');

        $this->assertSame(['b'], Record::query()->pluck('rkey')->all());
        $this->assertNotNull(Record::withHidden()->find($this->uri('a'))->moderated_at);
        $this->assertSame(1, $this->phpCount(), 'The hidden record leaves the counts.');

        $this->label($this->uri('a'), 'porn', '2026-02-01T00:00:00.000Z', neg: true);

        $this->assertSame(['a', 'b'], Record::query()->orderBy('rkey')->pluck('rkey')->all());
        $this->assertSame(2, $this->phpCount());
    }

    public function test_a_hiding_account_label_hides_everything_the_account_wrote_including_new_records(): void
    {
        $this->indexPost('a');
        $this->policy('spam', Target::ACCOUNT, 'hide');
        $this->label(self::ALICE, 'spam');

        $this->indexPost('b');

        $this->assertSame(0, Record::query()->count());
        $this->assertSame(2, Record::withHidden()->whereNotNull('moderated_at')->count());

        app(Policies::class)->clear(self::MOD, 'spam', Target::ACCOUNT);

        $this->assertSame(2, Record::query()->count(), 'Clearing the policy shows them again.');
    }

    public function test_account_status_and_labels_never_undo_each_other(): void
    {
        $this->indexPost('a');
        $this->indexPost('b');
        $this->label($this->uri('a'), 'porn');

        $status = new AccountStatus(app('db')->connection());
        $status->set([self::ALICE => ['active' => false, 'status' => 'deactivated']]);
        $status->set([self::ALICE => ['active' => true, 'status' => null]]);

        $this->assertSame(['b'], Record::query()->pluck('rkey')->all(), 'Reactivating keeps the labeled record hidden.');
        $this->assertSame(1, $this->phpCount());

        $status->set([self::ALICE => ['active' => false, 'status' => 'deactivated']]);
        $this->label($this->uri('a'), 'porn', '2026-02-01T00:00:00.000Z', neg: true);

        $this->assertSame(0, Record::query()->count(), 'Withdrawing the label keeps an inactive account hidden.');
        $this->assertSame(0, $this->phpCount());
    }

    public function test_self_labels_count_like_labels_and_follow_the_record(): void
    {
        $this->indexPost('a', ['labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'porn']]]]);

        $label = Label::query()->sole();
        $this->assertTrue($label->self);
        $this->assertSame(self::ALICE, $label->src);
        $this->assertSame(0, Record::query()->count(), 'A self-labeled porn record is hidden like a labeled one.');

        $this->indexPost('a', [], '3l2aaaaaaaaab');

        $this->assertSame(0, Label::query()->count(), 'The next version without the label drops it.');
        $this->assertSame(1, Record::query()->count());
    }
}
