<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\RecordValidation;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class RecordValidationTest extends TestCase
{
    protected const THING = 'com.example.thing';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-schema.cache.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function event(string $rkey, ?array $record, string $collection = self::THING, RecordAction $action = RecordAction::Create, string $rev = '3l2aaaaaaaaaa'): RecordEvent
    {
        return new RecordEvent(0, 'did:plc:alice', $collection, $rkey, $action, $record === null ? null : "bafy-{$rkey}", $rev, $record, true);
    }

    protected function index(RecordEvent ...$events): void
    {
        app(RecordIndexer::class)->handle(new Batch($events));
    }

    protected function indexed(string $rkey, string $collection = self::THING): ?Record
    {
        return Record::withTrashed()->find("at://did:plc:alice/{$collection}/{$rkey}");
    }

    public function test_nothing_is_checked_by_default(): void
    {
        $this->index($this->event('bad', ['text' => 123]));

        $this->assertNotNull($this->indexed('bad'));
    }

    public function test_drop_leaves_invalid_records_out_and_keeps_the_rest(): void
    {
        config()->set('atp-appview.index.validate', 'drop');
        Log::spy();

        $this->index(
            $this->event('good', ['text' => 'hello', 'createdAt' => '2026-10-18T00:00:00Z']),
            $this->event('missing', ['createdAt' => '2026-10-18T00:00:00Z']),
            $this->event('wrongtype', ['text' => 123]),
            $this->event('toolong', ['text' => str_repeat('a', 101)]),
            $this->event('extra', ['text' => 'hi', 'addedLater' => ['anything' => true]]),
            $this->event('other', ['whatever' => 1], 'com.example.unknown'),
        );

        $this->assertNotNull($this->indexed('good'));
        $this->assertNull($this->indexed('missing'));
        $this->assertNull($this->indexed('wrongtype'));
        $this->assertNull($this->indexed('toolong'));

        // Fields the lexicon does not mention are allowed, and a collection
        // without a lexicon is not checked.
        $this->assertNotNull($this->indexed('extra'));
        $this->assertNotNull($this->indexed('other', 'com.example.unknown'));

        $this->assertSame(3, app(RecordValidation::class)->count(self::THING));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, '3 invalid com.example.thing records left out of the index') && isset($context['example'], $context['problem']));
    }

    public function test_drop_keeps_an_earlier_valid_version_and_never_blocks_a_delete(): void
    {
        config()->set('atp-appview.index.validate', 'drop');

        $this->index($this->event('a', ['text' => 'valid']));
        $this->index($this->event('a', ['text' => 123], action: RecordAction::Update, rev: '3l2aaaaaaaaab'));

        $this->assertSame(['text' => 'valid'], $this->indexed('a')->record);

        $this->index($this->event('a', null, action: RecordAction::Delete, rev: '3l2aaaaaaaaac'));

        $this->assertNotNull($this->indexed('a')->deleted_at);
    }

    public function test_log_indexes_invalid_records_and_counts_them(): void
    {
        config()->set('atp-appview.index.validate', 'log');
        Log::spy();

        $this->index($this->event('bad', ['text' => 123]));

        $this->assertNotNull($this->indexed('bad'));
        $this->assertSame(1, app(RecordValidation::class)->count(self::THING));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'indexed anyway'));
    }

    public function test_an_unknown_mode_is_off(): void
    {
        config()->set('atp-appview.index.validate', 'strict');

        $this->assertSame(RecordValidation::OFF, RecordValidation::mode());
    }
}
