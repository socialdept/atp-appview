<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Ingest\TapFeatureUnavailableException;
use SocialDept\AtpAppview\Tests\TestCase;

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
        // Tap's router does not decode %3A, so the DID goes in the path as is.
        Http::assertSent(fn (Request $request) => $request->url() === 'http://tap.test/info/did:plc:alice'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_it_resyncs_one_repo_or_all_of_them(): void
    {
        Http::fake([
            'tap.test/resync/did:plc:alice' => Http::response(['did' => 'did:plc:alice', 'state' => 'desynchronized'], 200),
            // Strangers, including the DID supportsResync() asks about.
            'tap.test/resync/did:plc:stranger' => Http::response(['message' => 'repo not found'], 404),
            'tap.test/resync/did:plc:aaaa*' => Http::response(['message' => 'repo not found'], 404),
            'tap.test/resync-all' => Http::response(['count' => 18], 200),
        ]);
        $admin = new TapAdmin('http://tap.test');

        $this->assertSame('desynchronized', $admin->resyncRepo('did:plc:alice'));
        $this->assertNull($admin->resyncRepo('did:plc:stranger'));
        $this->assertSame(18, $admin->resyncAll());
        $this->assertTrue($admin->supportsResync());
    }

    public function test_a_tap_without_the_resync_routes_says_so(): void
    {
        Http::fake(['tap.test/*' => Http::response(['message' => 'Not Found'], 404)]);
        $admin = new TapAdmin('http://tap.test');

        $this->assertFalse($admin->supportsResync());

        $this->expectException(TapFeatureUnavailableException::class);
        $admin->resyncRepo('did:plc:alice');
    }
}
