<?php

namespace SocialDept\AtpAppview\Tests\Feature\Moderation;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Moderation\AccountActions;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Views\Parts\Moderation;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * With moderation left out: no label or account action tables, no Tack
 * webhook or commands, nobody banned, neutral view parts, and writes refuse.
 */
class ModerationFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', [Features::notifications(), Features::graph()]);
        $app['config']->set('atp-appview.labels.webhook.enabled', true);
        // As a config published before moderation was a feature lists it.
        $app['config']->set('atp-appview.ingest.handlers', [\SocialDept\AtpAppview\Index\RecordIndexer::class, LabelIndexer::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    public function test_moderation_left_out_keeps_nothing_applies_nothing_and_refuses_writes(): void
    {
        $this->assertFalse(Schema::hasTable('atp_labels'));
        $this->assertFalse(Schema::hasTable('atp_account_actions'));
        $this->assertFalse(Route::has('atp-appview.tack-webhook'));
        $this->assertArrayNotHasKey('appview:labels:sync', Artisan::all());
        $this->assertNotContains(LabelIndexer::class, array_map('get_class', Pipeline::handlers(app())));

        // A self-labelled record indexes, with nothing kept or hidden.
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, 'did:plc:bob', 'com.example.thing', 't1', RecordAction::Create, 'bafy-t1', '3l2aaaaaaaaaa', ['text' => 'hi', 'labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'porn']]]], true),
        ]));
        $record = Record::query()->firstOrFail();
        $this->assertNull($record->hidden_at);

        $this->assertFalse(app(AccountActions::class)->banned('did:plc:bob'));
        $this->assertSame([], app(AccountActions::class)->bannedAmong(['did:plc:bob']));
        $this->assertFalse(app(Labels::class)->account('did:plc:bob')->hide);
        $this->assertSame(['hide' => false], array_intersect_key((array) (new Moderation())->resolve(collect([$record]), null)[$record->uri] + ['hide' => false], ['hide' => 1]));
        $this->assertSame(0, DB::table('atp_records')->whereNotNull('moderated_at')->count());

        $this->expectException(LogicException::class);
        app(AccountActions::class)->ban('did:plc:bob');
    }
}
