<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class DidDocumentTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
    }

    protected function published($app): void
    {
        $app['config']->set('atp-client.service_auth.audience', 'did:web:app.example.com#atproto_appview');
        $app['config']->set('atp-appview.service.endpoint', 'https://app.example.com/');
    }

    protected function withoutEndpoint($app): void
    {
        $app['config']->set('atp-client.service_auth.audience', 'did:web:app.example.com#atproto_appview');
    }

    protected function withPlcAudience($app): void
    {
        $app['config']->set('atp-client.service_auth.audience', 'did:plc:abc#atproto_appview');
        $app['config']->set('atp-appview.service.endpoint', 'https://app.example.com');
    }

    protected function withBareAudience($app): void
    {
        $app['config']->set('atp-client.service_auth.audience', 'did:web:app.example.com');
        $app['config']->set('atp-appview.service.endpoint', 'https://app.example.com');
    }

    #[DefineEnvironment('published')]
    public function test_the_did_document_names_the_appview_service(): void
    {
        $this->getJson('/.well-known/did.json')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson([
                '@context' => ['https://www.w3.org/ns/did/v1'],
                'id' => 'did:web:app.example.com',
                'service' => [[
                    'id' => '#atproto_appview',
                    'type' => 'AtprotoAppView',
                    'serviceEndpoint' => 'https://app.example.com',
                ]],
            ]);
    }

    #[DefineEnvironment('withBareAudience')]
    public function test_an_audience_without_a_fragment_publishes_atproto_appview(): void
    {
        $this->getJson('/.well-known/did.json')->assertOk()->assertJsonPath('service.0.id', '#atproto_appview');
    }

    #[DefineEnvironment('withoutEndpoint')]
    public function test_nothing_is_served_without_an_endpoint(): void
    {
        $this->assertFalse(Route::has('atp-appview.did-document'));
    }

    #[DefineEnvironment('withPlcAudience')]
    public function test_nothing_is_served_for_a_did_that_is_not_did_web(): void
    {
        $this->assertFalse(Route::has('atp-appview.did-document'));
    }
}
