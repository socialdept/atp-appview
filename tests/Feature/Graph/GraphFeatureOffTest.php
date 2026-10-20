<?php

namespace SocialDept\AtpAppview\Tests\Feature\Graph;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * With the graph feature left out, follows and blocks configured for it do
 * nothing: no fields are kept, no table is made, nothing is filtered.
 */
class GraphFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', [Features::moderation(), Features::notifications()]);
        $app['config']->set('atp-appview.graph.blocks', ['collection' => 'com.example.block', 'path' => 'subject']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    public function test_the_graph_left_out_keeps_nothing_and_filters_nothing(): void
    {
        $this->assertFalse(Schema::hasTable('atp_mutes'));
        $this->assertNull(app(RecordValues::class)->field('com.example.block', 'subject'));

        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, 'did:plc:alice', 'com.example.block', 'b1', RecordAction::Create, 'bafy-b1', '3l2aaaaaaaaaa', ['subject' => 'did:plc:bob'], true),
            new RecordEvent(2, 'did:plc:bob', 'com.example.thing', 't1', RecordAction::Create, 'bafy-t1', '3l2aaaaaaaaaa', ['text' => 'hi'], true),
        ]));

        $this->assertSame([], app(Graph::class)->blocking('did:plc:alice'));
        $this->assertSame([], app(Graph::class)->muted('did:plc:alice'));
        $this->assertCount(1, Record::collection('com.example.thing')->visibleTo('did:plc:alice')->get());
        $this->assertSame(['following' => false, 'followedBy' => false, 'blocking' => false, 'blockedBy' => false, 'muted' => false], app(Graph::class)->relationships('did:plc:alice', ['did:plc:bob'])['did:plc:bob']);

        $this->expectException(LogicException::class);
        app(Graph::class)->mute('did:plc:alice', 'did:plc:bob');
    }
}
