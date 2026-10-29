<?php

namespace SocialDept\AtpAppview\Tests\Feature\Spaces;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpAppview\Spaces\SpaceBlobs;
use SocialDept\AtpAppview\Spaces\SpaceBlobUrls;
use SocialDept\AtpAppview\Spaces\SpaceGrants;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;
use SocialDept\AtpSpaces\SpaceTypeRegistry;
use SocialDept\AtpSpaces\Support\Cid;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Space blobs served through the AppView: a signed URL only for a viewer
 * allowed to read the space, expiring with their grant, and a route that
 * fetches the blob with the space's sync credential, checks it against its
 * CID, keeps it on a disk and serves it privately.
 */
class SpaceBlobsTest extends TestCase
{
    protected const AUTHORITY = 'did:plc:authority';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const BYTES = "\x89PNG not really";

    protected Spaces&MockInterface $spaces;

    protected SpaceReader&MockInterface $reader;

    protected CredentialStore $credentials;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpSpacesServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', [Features::spaces()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        Queue::fake();
        Storage::fake();

        $this->credentials = new class () implements CredentialStore {
            /** @var array<string, SpaceCredential> */
            public array $credentials = [];

            public function get(SpaceRef $space, string $userDid): ?SpaceCredential
            {
                return $this->credentials[$space.'|'.$userDid] ?? null;
            }

            public function put(SpaceCredential $credential): void
            {
                $this->credentials[$credential->space.'|'.$credential->userDid] = $credential;
            }

            public function forget(SpaceRef $space, string $userDid): void
            {
                unset($this->credentials[$space.'|'.$userDid]);
            }
        };
        $this->app->instance(CredentialStore::class, $this->credentials);

        $this->reader = Mockery::mock(SpaceReader::class);
        $this->spaces = Mockery::mock(Spaces::class);
        $this->spaces->shouldReceive('types')->andReturn(new SpaceTypeRegistry())->byDefault();
        $this->spaces->shouldReceive('readerFor')->andReturn($this->reader)->byDefault();
        $this->app->instance(Spaces::class, $this->spaces);

        // Followed with Bob's credential, so Bob holds a grant and the
        // space syncs, and fetches blobs, with his credential.
        app(SpaceSync::class)->follow($this->credential(self::BOB));
    }

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    protected function credential(string $did, int $expiresIn = 600): SpaceCredential
    {
        return new SpaceCredential(
            $this->space(),
            $did,
            new SpaceToken(SpaceTokenType::Credential, [], ['exp' => time() + $expiresIn], 'header.payload.signature'),
            CredentialKey::generate(),
        );
    }

    protected function cid(string $bytes = self::BYTES): string
    {
        return Cid::fromBinary(Cid::RAW_PREFIX.hash('sha256', $bytes, true))->toString();
    }

    protected function urls(): SpaceBlobUrls
    {
        return app(SpaceBlobUrls::class);
    }

    protected function url(?string $viewer = self::BOB, ?string $type = 'image/png'): ?string
    {
        return $this->urls()->url($this->space(), self::ALICE, $this->cid(), $viewer, $type);
    }

    protected function cached(): bool
    {
        return Storage::exists(app(SpaceBlobs::class)->path($this->space(), $this->cid()));
    }

    protected function expiresOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (int) $query['expires'];
    }

    protected function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();
    }

    public function test_a_viewer_with_a_grant_gets_a_url_and_the_bytes(): void
    {
        $this->reader->shouldReceive('getBlob')->with(self::ALICE, $this->cid())->once()->andReturn(self::BYTES);

        $url = $this->url();

        $this->assertNotNull($url);
        $this->assertStringContainsString('/atp-appview/space-blobs/'.self::ALICE.'/'.$this->cid(), $url);

        $response = $this->get($url)->assertOk();

        $this->assertSame(self::BYTES, $this->body($response));
        $this->assertTrue($this->cached());
    }

    public function test_a_viewer_without_a_grant_gets_no_url(): void
    {
        $this->assertNull($this->url(self::CAROL));
        $this->assertNull($this->url(null));
    }

    public function test_a_viewer_whose_grant_expired_gets_no_url(): void
    {
        app(SpaceGrants::class)->grant($this->credential(self::CAROL, 60));
        $this->assertNotNull($this->url(self::CAROL));

        $this->travel(2)->minutes();

        $this->assertNull($this->url(self::CAROL));
    }

    public function test_a_blob_ref_gives_its_cid_and_type(): void
    {
        $url = $this->urls()->blob($this->space(), ['$type' => 'blob', 'ref' => ['$link' => $this->cid()], 'mimeType' => 'image/webp', 'size' => 15], self::ALICE, self::BOB);

        $this->assertStringContainsString($this->cid(), (string) $url);
        $this->assertStringContainsString('type=image%2Fwebp', (string) $url);
        $this->assertNull($this->urls()->blob($this->space(), ['$type' => 'blob'], self::ALICE, self::BOB));
    }

    public function test_the_url_never_outlives_the_grant(): void
    {
        app(SpaceGrants::class)->grant($this->credential(self::CAROL, 120));

        $expires = $this->expiresOf((string) $this->url(self::CAROL));

        $this->assertLessThanOrEqual(time() + 120, $expires);
        $this->assertGreaterThan(time() + 100, $expires);
    }

    public function test_the_url_never_outlives_the_configured_lifetime(): void
    {
        config()->set('atp-appview.spaces.blobs.lifetime', 60);

        $this->assertLessThanOrEqual(time() + 60, $this->expiresOf((string) $this->url()));
    }

    public function test_an_access_without_an_end_signs_for_the_lifetime(): void
    {
        $this->app->instance(SpaceAccess::class, new class () implements SpaceAccess {
            public function allows(SpaceRef $space, ?string $viewer): bool
            {
                return $viewer === 'did:plc:carol';
            }
        });

        $this->assertNull($this->url(self::ALICE));
        $this->assertSame(time() + 600, $this->expiresOf((string) $this->url(self::CAROL)));
    }

    public function test_an_expired_url_is_refused(): void
    {
        $this->reader->shouldNotReceive('getBlob');
        $url = (string) $this->url();

        $this->travel(11)->minutes();

        $this->get($url)->assertForbidden()->assertJson(['error' => 'ExpiredSignature']);
    }

    public function test_a_tampered_or_unsigned_url_is_refused(): void
    {
        $this->reader->shouldNotReceive('getBlob');
        $url = (string) $this->url();

        $this->get(str_replace(self::ALICE, self::CAROL, $url))->assertForbidden()->assertJson(['error' => 'InvalidSignature']);
        $this->get(str_replace('image%2Fpng', 'text%2Fhtml', $url))->assertForbidden()->assertJson(['error' => 'InvalidSignature']);
        $this->get(strtok($url, '?').'?space='.urlencode((string) $this->space()))->assertForbidden()->assertJson(['error' => 'InvalidSignature']);
    }

    public function test_a_cid_mismatch_is_refused_and_not_cached(): void
    {
        $this->reader->shouldReceive('getBlob')->twice()->andReturn('something else');
        $url = (string) $this->url();

        $this->get($url)->assertStatus(502)->assertJson(['error' => 'UpstreamFailure']);
        $this->assertFalse($this->cached());

        // Not kept, so the next request asks again.
        $this->get($url)->assertStatus(502);
    }

    public function test_a_fetch_failure_answers_with_an_error(): void
    {
        $this->reader->shouldReceive('getBlob')->once()->andThrow(new SpacesException('GET getBlob failed: HTTP 500'));

        $this->get((string) $this->url())->assertStatus(502);
        $this->assertFalse($this->cached());
    }

    public function test_a_second_request_is_served_from_the_cache(): void
    {
        $this->reader->shouldReceive('getBlob')->once()->andReturn(self::BYTES);

        $this->get((string) $this->url())->assertOk();
        $response = $this->get((string) $this->url(self::BOB))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame(self::BYTES, $this->body($response));
        $this->assertSame((string) strlen(self::BYTES), $response->headers->get('Content-Length'));
    }

    public function test_a_stale_space_answers_with_an_error(): void
    {
        $url = (string) $this->url();
        $this->credentials->forget($this->space(), self::BOB);
        $this->reader->shouldNotReceive('getBlob');

        $this->get($url)->assertStatus(503)->assertJson(['error' => 'SpaceUnavailable']);
        $this->assertNotNull(app(SpaceSync::class)->state($this->space())->stale_at);
        $this->assertFalse($this->cached());
    }

    public function test_a_space_not_followed_answers_not_found(): void
    {
        $url = (string) $this->url();
        DB::table('atp_spaces')->update(['deleted_at' => now()]);

        $this->get($url)->assertNotFound()->assertJson(['error' => 'SpaceNotFound']);
    }

    public function test_deleting_the_space_drops_its_blobs(): void
    {
        $this->reader->shouldReceive('getBlob')->once()->andReturn(self::BYTES);
        $url = (string) $this->url();
        $this->get($url)->assertOk();

        app(SpaceSync::class)->deleted($this->space());

        $this->assertFalse($this->cached());
        $this->get($url)->assertNotFound();
    }

    public function test_unfollowing_the_space_drops_its_blobs(): void
    {
        $this->reader->shouldReceive('getBlob')->once()->andReturn(self::BYTES);
        $this->get((string) $this->url())->assertOk();

        app(SpaceSync::class)->unfollow($this->space());

        $this->assertFalse($this->cached());
    }

    public function test_an_image_is_sent_privately_and_in_place(): void
    {
        $this->reader->shouldReceive('getBlob')->andReturn(self::BYTES);
        $url = (string) $this->url();
        $lifetime = $this->expiresOf($url) - time();

        $response = $this->get($url)
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Content-Disposition');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cacheControl);
        preg_match('/max-age=(\d+)/', $cacheControl, $match);
        $this->assertLessThanOrEqual($lifetime, (int) $match[1]);
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_other_types_are_sent_as_attachments(): void
    {
        $this->reader->shouldReceive('getBlob')->andReturn(self::BYTES);

        $this->get((string) $this->url(type: 'application/pdf'))
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$this->cid().'"');

        $this->get((string) $this->url(type: 'image/svg+xml'))
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$this->cid().'"');

        $this->get((string) $this->url(type: 'video/mp4'))->assertHeaderMissing('Content-Disposition');
    }

    public function test_a_missing_or_unusable_type_is_sent_as_octet_stream(): void
    {
        $this->reader->shouldReceive('getBlob')->andReturn(self::BYTES);

        $this->get((string) $this->url(type: null))
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$this->cid().'"');

        $this->get((string) $this->url(type: "text/html\r\nX-Evil: 1"))
            ->assertHeader('Content-Type', 'application/octet-stream');
    }
}
