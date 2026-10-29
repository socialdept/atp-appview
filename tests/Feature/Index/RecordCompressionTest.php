<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordCompression;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Runs its Postgres cases on the database DB_CONNECTION names, and checks
 * that every other database is left alone.
 */
class RecordCompressionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function tearDown(): void
    {
        // The columns' compression outlives the test on a kept schema.
        if (DB::connection()->getDriverName() === 'pgsql') {
            (new RecordCompression(DB::connection()))->apply(null);
        }

        parent::tearDown();
    }

    protected function pgsqlOnly(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Column compression is a Postgres setting.');
        }
    }

    /**
     * A record large enough to be compressed, and one small enough to be
     * stored as it is.
     */
    protected function records(): void
    {
        foreach (['big' => str_repeat('a long and repetitive body ', 400), 'small' => 'short'] as $rkey => $text) {
            DB::table('atp_records')->insert([
                'uri' => "at://did:plc:alice/app.example.post/{$rkey}",
                'did' => 'did:plc:alice',
                'collection' => 'app.example.post',
                'rkey' => $rkey,
                'cid' => "cid-{$rkey}",
                'record' => json_encode(['text' => $text]),
                'rev' => '3l2aaaaaaaaaa',
                'indexed_at' => now(),
                'sort_at' => now(),
            ]);
        }
    }

    protected function stored(string $rkey): ?string
    {
        return DB::selectOne('select pg_column_compression(record) as method from atp_records where rkey = ?', [$rkey])->method;
    }

    public function test_it_does_nothing_off_postgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('Postgres compresses.');
        }

        config()->set('atp-appview.retention.compression', 'lz4');

        $this->artisan('appview:compress', ['--recompress' => true])
            ->expectsOutputToContain('Nothing to do on')
            ->assertSuccessful();
    }

    public function test_it_sets_the_record_columns_to_lz4_and_back(): void
    {
        $this->pgsqlOnly();
        config()->set('atp-appview.retention.compression', 'lz4');

        $this->artisan('appview:compress', ['--dry-run' => true])->expectsOutputToContain('2 columns would be set to lz4')->assertSuccessful();
        $this->assertSame(['atp_records.record' => null, 'atp_records.repair' => null], (new RecordCompression(DB::connection()))->columns());

        $this->artisan('appview:compress')->assertSuccessful();
        $this->assertSame(['atp_records.record' => 'lz4', 'atp_records.repair' => 'lz4'], (new RecordCompression(DB::connection()))->columns());

        $this->artisan('appview:compress')->expectsOutputToContain('0 columns set to lz4')->assertSuccessful();

        config()->set('atp-appview.retention.compression', null);
        $this->artisan('appview:compress')->assertSuccessful();
        $this->assertSame(['atp_records.record' => null, 'atp_records.repair' => null], (new RecordCompression(DB::connection()))->columns());
    }

    public function test_new_rows_are_compressed_and_old_ones_only_once_recompressed(): void
    {
        $this->pgsqlOnly();
        DB::statement('alter table atp_records alter column record set compression pglz');
        $this->records();
        $this->assertSame('pglz', $this->stored('big'));
        $this->assertNull($this->stored('small'), 'Small values are stored as they are.');

        config()->set('atp-appview.retention.compression', 'lz4');
        $this->artisan('appview:compress')->assertSuccessful();

        $this->assertSame('pglz', $this->stored('big'), 'Rows already stored keep their compression.');

        $this->artisan('appview:compress', ['--recompress' => true, '--dry-run' => true])->expectsOutputToContain('1 rows would be rewritten to lz4')->assertSuccessful();
        $this->assertSame('pglz', $this->stored('big'));

        $before = DB::table('atp_records')->where('rkey', 'big')->value('record');
        $this->artisan('appview:compress', ['--recompress' => true, '--chunk' => 1])->expectsOutputToContain('1 rows rewritten to lz4')->assertSuccessful();

        $this->assertSame('lz4', $this->stored('big'));
        $this->assertNull($this->stored('small'));
        $this->assertSame(json_decode($before, true), json_decode(DB::table('atp_records')->where('rkey', 'big')->value('record'), true), 'The body is unchanged.');

        $this->artisan('appview:compress', ['--recompress' => true])->expectsOutputToContain('0 rows rewritten')->assertSuccessful();
    }

    public function test_recompressing_stops_at_the_deadline(): void
    {
        $this->pgsqlOnly();
        $this->records();

        $result = (new RecordCompression(DB::connection()))->recompress('lz4', 1000, microtime(true) - 1);

        $this->assertSame(['rows' => 0, 'done' => false], $result);
    }

    public function test_a_server_without_lz4_or_before_postgres_14_is_refused(): void
    {
        $this->pgsqlOnly();
        config()->set('atp-appview.retention.compression', 'lz4');

        $old = new class (DB::connection()) extends RecordCompression {
            protected function version(): int
            {
                return 130000;
            }
        };

        $withoutLz4 = new class (DB::connection()) extends RecordCompression {
            protected function available(): array
            {
                return ['pglz'];
            }
        };

        $this->assertSame('Column compression needs Postgres 14 or later.', $old->unsupported('lz4'));
        $this->assertSame('This Postgres server was built without lz4.', $withoutLz4->unsupported('lz4'));
        $this->assertSame('Unknown compression "zstd". Use lz4, or null for the server\'s default.', (new RecordCompression(DB::connection()))->unsupported('zstd'));
        $this->assertNull((new RecordCompression(DB::connection()))->unsupported('lz4'));

        $this->expectException(\RuntimeException::class);
        $withoutLz4->apply('lz4');
    }

    public function test_an_unknown_compression_fails_the_command(): void
    {
        $this->pgsqlOnly();
        config()->set('atp-appview.retention.compression', 'zstd');

        $this->artisan('appview:compress')->expectsOutputToContain('Unknown compression')->assertFailed();
        $this->assertSame(['atp_records.record' => null, 'atp_records.repair' => null], (new RecordCompression(DB::connection()))->columns());
    }
}
