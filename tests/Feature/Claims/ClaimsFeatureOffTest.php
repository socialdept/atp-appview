<?php

namespace SocialDept\AtpAppview\Tests\Feature\Claims;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LogicException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Claims\Claims;
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
 * With the claims feature left out, collections configured for it do
 * nothing: no table, no checks, and verified() refuses.
 */
class ClaimsFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.ingest.collections', ['com.example.site']);
        $app['config']->set('atp-appview.features', []);
        $app['config']->set('atp-appview.claims.collections', ['com.example.site' => ['checker' => SiteChecker::class]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        SiteChecker::$checked = [];
        $this->artisan('migrate')->run();
    }

    public function test_claims_left_out_keep_nothing_and_verified_refuses_naming_the_feature(): void
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, 'did:plc:alice', 'com.example.site', 'self', RecordAction::Create, 'bafy-s1', '3l2aaaaaaaaaa', ['url' => 'https://alice.example'], true),
        ]));

        $this->assertFalse(Schema::hasTable(Claims::TABLE));
        $this->assertSame(1, Record::query()->count());
        $this->assertSame([], SiteChecker::$checked);
        $this->assertArrayNotHasKey('appview:claims:refresh', Artisan::all());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Features::claims()');

        Record::query()->verified()->get();
    }
}
