<?php

namespace SocialDept\AtpAppview\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Every migration, core and each feature's, runs up, down and up again.
 * Runs on SQLite by default, and on the database DB_CONNECTION names, as
 * the MySQL job in CI does.
 */
class MigrationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-appview.features', [
            Features::moderation(),
            Features::notifications(),
            Features::graph(),
            Features::feeds(),
            Features::search(),
            Features::standing(),
            Features::realtime(),
            Features::spaces(),
        ]);
    }

    public function test_every_migration_runs_rolls_back_and_runs_again(): void
    {
        $migrations = count(glob(__DIR__.'/../../database/migrations/{,*/}*.php', GLOB_BRACE));

        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->assertSame($migrations, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasTable('atp_ingest_rejections'));
        $this->assertTrue(Schema::hasTable('atp_labels'));

        $tables = array_diff(array_unique(array_column(Schema::getTables(), 'name')), ['migrations']);

        $this->artisan('migrate:reset')->assertSuccessful();
        $this->assertSame(0, DB::table('migrations')->count());

        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} is left after rolling back.");
        }

        $this->artisan('migrate')->assertSuccessful();
        $this->assertSame($migrations, DB::table('migrations')->count());
    }

    /**
     * Before its keys were shortened, the failed deliveries migration failed
     * on MySQL after creating atp_failed_deliveries, which MySQL keeps. An
     * install left that way must be able to migrate.
     */
    public function test_an_install_left_by_the_failed_deliveries_migration_can_migrate(): void
    {
        $name = '2026_10_19_000003_create_atp_failed_deliveries_tables';
        $migrations = count(glob(__DIR__.'/../../database/migrations/{,*/}*.php', GLOB_BRACE));

        $this->artisan('migrate:fresh')->assertSuccessful();

        // What a failed run left: the first table, and no record of the run.
        Schema::dropIfExists('atp_ingest_repairs');
        Schema::dropIfExists('atp_ingest_rejections');
        Schema::table('atp_records', fn ($table) => $table->dropColumn('repair'));
        DB::table('migrations')->where('migration', $name)->delete();

        $this->assertTrue(Schema::hasTable('atp_failed_deliveries'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame($migrations, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasTable('atp_ingest_rejections'));
        $this->assertTrue(Schema::hasTable('atp_ingest_repairs'));
        $this->assertTrue(Schema::hasColumn('atp_records', 'repair'));
    }

    /**
     * Repos indexed before created_at existed get the earliest time the
     * index holds for them, and the column is indexed for "since" queries.
     */
    public function test_identities_already_indexed_get_when_they_were_first_seen(): void
    {
        $name = '2026_11_08_000001_add_created_at_to_atp_identities_table';

        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('atp_identities', 'created_at'), "{$name} is no longer the last migration.");

        DB::table('atp_identities')->insert([
            ['did' => 'did:plc:alice', 'active' => true, 'updated_at' => '2026-11-05 12:00:00'],
            ['did' => 'did:plc:bob', 'active' => true, 'updated_at' => '2026-11-03 09:00:00'],
        ]);

        foreach ([['a', '2026-11-04 08:00:00'], ['b', '2026-11-02 07:00:00']] as [$rkey, $at]) {
            DB::table('atp_records')->insert(['uri' => "at://did:plc:alice/app.example.post/{$rkey}", 'did' => 'did:plc:alice', 'collection' => 'app.example.post', 'rkey' => $rkey, 'rev' => '3l2aaaaaaaaaa', 'indexed_at' => $at]);
        }

        DB::table('atp_records')->insert(['uri' => 'at://did:plc:bob/app.example.post/c', 'did' => 'did:plc:bob', 'collection' => 'app.example.post', 'rkey' => 'c', 'rev' => '3l2aaaaaaaaaa', 'indexed_at' => '2026-11-04 08:00:00']);

        $this->artisan('migrate')->assertSuccessful();

        $created = DB::table('atp_identities')->orderBy('did')->pluck('created_at', 'did')->map(fn ($at) => substr((string) $at, 0, 19))->all();

        $this->assertSame(['did:plc:alice' => '2026-11-02 07:00:00', 'did:plc:bob' => '2026-11-03 09:00:00'], $created, 'The oldest record, or updated_at when that is earlier.');
        $this->assertTrue(Schema::hasIndex('atp_identities', ['created_at']));
    }
}
