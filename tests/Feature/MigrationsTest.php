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
}
