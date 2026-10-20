<?php

namespace SocialDept\AtpAppview\Tests\Feature\Features;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Graph;
use SocialDept\AtpAppview\Features\Moderation;
use SocialDept\AtpAppview\Features\Notifications;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\Fixtures\Features\AcmeFeature;
use SocialDept\AtpAppview\Tests\Fixtures\Features\NeedyFeature;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Features turned on in atp-appview.features, Fortify style, each
 * registering what it adds through the AppView registrar.
 */
class FeaturesTest extends TestCase
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
        $app['config']->set('atp-appview.features', [
            Features::moderation(),
            Features::notifications(['latest' => 7]),
            Features::graph(),
            Features::feature(AcmeFeature::class, ['loud' => true]),
        ]);
        $app['config']->set('atp-appview.acme', ['greeting' => 'howdy']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        AcmeFeature::$batches = 0;
        AcmeFeature::$written = [];
        $this->artisan('migrate')->run();
    }

    public function test_the_list_says_what_is_on(): void
    {
        $this->assertTrue(Features::enabled(Features::notifications()));
        $this->assertTrue(Features::enabled(AcmeFeature::class));
        $this->assertFalse(Features::enabled(NeedyFeature::class));
        $this->assertSame([Moderation::class, Notifications::class, Graph::class, AcmeFeature::class], Features::all());
    }

    public function test_options_go_over_the_feature_config_and_the_app_s_own_values_over_defaults(): void
    {
        $this->assertSame(7, config('atp-appview.notifications.latest'));
        $this->assertSame([], config('atp-appview.notifications.reasons'), 'The rest of the section keeps its defaults.');
        $this->assertSame(['greeting' => 'howdy', 'loud' => true], config('atp-appview.acme'));
    }

    public function test_a_feature_adds_migrations_handlers_fields_routes_commands_and_xrpc_methods(): void
    {
        $this->assertTrue(Schema::hasTable('acme_things'));
        $this->assertArrayHasKey('acme:tidy', Artisan::all());
        $this->get('/acme/ping')->assertOk()->assertSee('pong');
        $this->assertSame(['pong' => 'howdy'], Xrpc::query('com.acme.ping'), 'Served without a viewer, as registered.');
        $this->assertNotNull(app(RecordValues::class)->field('com.example.thing', 'mood'));

        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, 'did:plc:bob', 'com.example.thing', 'a', RecordAction::Create, 'bafy-a', '3l2aaaaaaaaaa', ['text' => 'a', 'mood' => 'calm'], true),
        ]));

        $this->assertSame(1, AcmeFeature::$batches);
        $this->assertSame(['at://did:plc:bob/com.example.thing/a'], AcmeFeature::$written);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('atp_record_values')->where('key_id', app(RecordValues::class)->keyId(app(RecordValues::class)->field('com.example.thing', 'mood')))->count());
    }

    public function test_read_filters_leave_accounts_out_of_what_a_viewer_reads(): void
    {
        foreach (['did:plc:bob', 'did:plc:mallory'] as $i => $did) {
            Pipeline::fromConfig(app())->run(new Batch([
                new RecordEvent($i + 1, $did, 'com.example.thing', "t{$i}", RecordAction::Create, "bafy-{$i}", '3l2aaaaaaaaaa', ['text' => 'x'], true),
            ]));
        }

        $this->assertSame(['did:plc:bob'], Record::collection('com.example.thing')->visibleTo('did:plc:alice')->pluck('did')->all());
        $this->assertCount(2, Record::collection('com.example.thing')->visibleTo('did:plc:carol')->get());
    }

    public function test_schedules_are_only_registered_when_the_app_asks(): void
    {
        $schedule = new Schedule();
        $this->assertSame([], $schedule->events());

        Features::schedule($schedule);

        $this->assertCount(1, $schedule->events());
        $this->assertStringContainsString('acme:tidy', $schedule->events()[0]->command);
    }

    public function test_a_feature_needing_one_that_is_off_fails_with_its_name(): void
    {
        try {
            (new AppView())->load([NeedyFeature::class], app());
            $this->fail('Expected the missing feature to be named.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(AcmeFeature::class, $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        (new AppView())->load([\stdClass::class], app());
    }
}
