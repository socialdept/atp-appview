<?php

namespace SocialDept\AtpAppview\Tests;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Runs every test on the database DB_CONNECTION names, in-memory SQLite by
 * default. Each test starts on an empty database, as it does in memory.
 *
 * On a database that outlives the test, dropping every table and migrating
 * again for each test is slow, so the schema the previous test of the same
 * class migrated is kept when that test changed it only through a plain
 * migrate: the tables it wrote to are emptied and its migrate does nothing.
 * Anything else, a rollback or a table a test creates itself, drops it all.
 */
abstract class TestCase extends Orchestra
{
    /**
     * The class whose migrated schema the database holds, if one may be kept.
     */
    protected static ?string $migratedBy = null;

    /**
     * The tables the previous test inserted rows into.
     *
     * @var array<string, true>
     */
    protected static array $written = [];

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getConfig('database') === ':memory:') {
            return;
        }

        $this->startFromAnEmptyDatabase();
        $this->watchTheDatabase();
    }

    protected function startFromAnEmptyDatabase(): void
    {
        $kept = static::$migratedBy === static::class;
        $written = array_keys(static::$written);

        static::$migratedBy = null;
        static::$written = [];

        if (! $kept) {
            Schema::dropAllTables();

            return;
        }

        Schema::withoutForeignKeyConstraints(function () use ($written) {
            foreach (array_diff($written, ['migrations']) as $table) {
                DB::table($table)->truncate();
            }
        });
    }

    /**
     * Notes the tables each statement writes to, and whether one changed the
     * schema outside a plain migrate, then decides after the test whether
     * the next one may keep what it migrated.
     */
    protected function watchTheDatabase(): void
    {
        $migrating = 0;
        $changed = false;
        $written = [];

        Event::listen(MigrationsStarted::class, function (MigrationsStarted $event) use (&$migrating, &$changed) {
            $migrating++;
            $changed = $changed || $event->method !== 'up';
        });

        Event::listen(MigrationsEnded::class, function () use (&$migrating) {
            $migrating--;
        });

        DB::connection()->beforeExecuting(function (string $query) use (&$migrating, &$changed, &$written) {
            if ($migrating > 0) {
                return;
            }

            if (preg_match('/^\s*(?:insert|replace)(?:\s+ignore)?\s+into\s+[`"]?([\w.]+)/i', $query, $matches)) {
                $written[$matches[1]] = true;
            } elseif (preg_match('/^\s*(?:create|alter|drop|rename)\b/i', $query) && ! preg_match('/^\s*create table [`"]?migrations\b/i', $query)) {
                $changed = true;
            }
        });

        $this->beforeApplicationDestroyed(function () use (&$changed, &$written) {
            static::$written = $written;

            if (! $changed && $this->migratedEverything()) {
                static::$migratedBy = static::class;
            }
        });
    }

    /**
     * Whether the migrations table lists exactly the migrations a plain
     * migrate runs for this test's features.
     */
    protected function migratedEverything(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        $migrator = $this->app['migrator'];
        $expected = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $ran = DB::table('migrations')->pluck('migration')->all();

        sort($expected);
        sort($ran);

        return $expected === $ran;
    }

    /**
     * Asserts a record read back from atp_records is the one expected. MySQL's
     * json and Postgres's jsonb keep object keys in an order of their own,
     * so on those only the keys and values are compared, not their order.
     */
    protected function assertSameRecord(array $expected, mixed $actual): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' && is_array($actual)) {
            [$expected, $actual] = [$this->sortedKeys($expected), $this->sortedKeys($actual)];
        }

        $this->assertSame($expected, $actual);
    }

    protected function sortedKeys(array $value): array
    {
        ksort($value);

        return array_map(fn (mixed $item) => is_array($item) ? $this->sortedKeys($item) : $item, $value);
    }
}
