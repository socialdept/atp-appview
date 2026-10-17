<?php

namespace SocialDept\AtpAppview\Tests\Feature\Blocklist;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Blocklist\BlockKind;
use SocialDept\AtpAppview\Models\Block;

class BlockCommandsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-appview.ingest.admin_url', 'http://tap.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Http::fake(['http://tap.test/*' => Http::response([], 200)]);
    }

    public function test_a_rule_is_added_with_its_scope_and_note(): void
    {
        $this->artisan('appview:block', ['subject' => 'did:plc:bad', '--collection' => 'app.example.like', '--note' => 'like spam'])
            ->expectsOutputToContain('did:plc:bad')
            ->assertSuccessful();

        $block = Block::sole();
        $this->assertSame(BlockKind::Did, $block->kind);
        $this->assertSame('app.example.like', $block->collection);
        $this->assertSame('like spam', $block->note);
    }

    public function test_rules_are_read_from_a_file_with_comments_as_notes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'blocks');
        file_put_contents($file, implode("\n", [
            '# known bad actors',
            'did:plc:one                       # reply spam',
            'did:plc:two  app.example.graph.*',
            '',
            'https://*.spam.example/           # whole host',
        ]));

        $this->artisan('appview:block', ['--file' => $file])->assertSuccessful();
        unlink($file);

        $this->assertSame([
            ['host', '*.spam.example', '*', 'whole host'],
            ['did', 'did:plc:one', '*', 'reply spam'],
            ['did', 'did:plc:two', 'app.example.graph.*', null],
        ], Block::query()->orderBy('kind', 'desc')->orderBy('subject')->get()->map(fn (Block $b) => [$b->kind->value, $b->subject, $b->collection, $b->note])->all());
    }

    public function test_a_subject_that_does_not_parse_fails(): void
    {
        $this->artisan('appview:block', ['subject' => 'not a host'])->assertFailed();
        $this->artisan('appview:block')->assertFailed();

        $this->assertSame(0, Block::count());
    }

    public function test_rules_are_listed_and_removed(): void
    {
        $this->artisan('appview:block', ['subject' => 'did:plc:bad', '--note' => 'spam ring'])->assertSuccessful();

        $this->artisan('appview:blocks')->expectsOutputToContain('spam ring')->assertSuccessful();
        $this->artisan('appview:unblock', ['subject' => 'did:plc:bad'])->expectsOutputToContain('1 repo sent to Tap')->assertSuccessful();
        $this->artisan('appview:unblock', ['subject' => 'did:plc:bad'])->assertFailed();
        $this->artisan('appview:blocks')->expectsOutputToContain('No block rules')->assertSuccessful();
    }
}
