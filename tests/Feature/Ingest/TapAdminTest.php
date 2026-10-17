<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\TapAdmin;

class TapAdminTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    public function test_it_adds_and_removes_repos_with_basic_auth(): void
    {
        Http::fake(['tap.test/*' => Http::response([], 200)]);
        $admin = new TapAdmin('http://tap.test/', 'secret');

        $admin->addRepos(['did:plc:alice', 'did:plc:bob']);
        $admin->removeRepos(['did:plc:bob']);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://tap.test/repos/add'
            && $request['dids'] === ['did:plc:alice', 'did:plc:bob']
            && $request->header('Authorization') === ['Basic '.base64_encode('admin:secret')]);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://tap.test/repos/remove'
            && $request['dids'] === ['did:plc:bob']);
    }

    public function test_it_reads_repo_info_and_stats(): void
    {
        Http::fake([
            'tap.test/info/*' => Http::response(['did' => 'did:plc:alice', 'state' => 'active'], 200),
            'tap.test/stats/repo-count' => Http::response(['repo_count' => 42], 200),
            'tap.test/health' => Http::response('ok', 200),
        ]);
        $admin = new TapAdmin('http://tap.test');

        $this->assertSame('active', $admin->info('did:plc:alice')['state']);
        $this->assertSame(42, $admin->stats('repo-count')['repo_count']);
        $this->assertTrue($admin->healthy());
        Http::assertSent(fn (Request $request) => $request->url() === 'http://tap.test/info/did%3Aplc%3Aalice'
            && ! $request->hasHeader('Authorization'));
    }
}
