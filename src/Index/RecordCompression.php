<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Compression for stored record bodies, as atp-appview.retention.compression
 * asks: LZ4 on Postgres 14 or later, built with it.
 *
 * Postgres compresses a large value before storing it, with pglz unless a
 * column says otherwise. LZ4 compresses and decompresses several times
 * faster, at about the same size for JSON. Setting a column's compression
 * changes only what is written from then on: rows already stored keep their
 * pglz values until rewritten, which recompress() does.
 *
 * MySQL, MariaDB and SQLite have no safe column equivalent, so there it does
 * nothing. MariaDB's COMPRESSED columns cannot be indexed and rebuild the
 * table to add, and InnoDB's page compression is a table option that
 * depends on the filesystem, which is the operator's choice, not the
 * package's.
 */
class RecordCompression
{
    /**
     * The JSON columns holding record bodies, by table.
     */
    public const COLUMNS = [
        'atp_records' => ['record', 'repair'],
        'atp_space_records' => ['record', 'repair'],
    ];

    public const METHODS = ['lz4', 'pglz'];

    public function __construct(
        protected Connection $connection,
    ) {
    }

    /**
     * The compression asked for in config, or null for the server's default.
     */
    public static function configured(): ?string
    {
        $method = config('atp-appview.retention.compression');

        return $method === null || $method === '' || $method === false ? null : strtolower((string) $method);
    }

    /**
     * Whether this database stores record bodies with column compression.
     */
    public function applies(): bool
    {
        return $this->connection->getDriverName() === 'pgsql';
    }

    /**
     * Why the method cannot be used here, or null when it can.
     */
    public function unsupported(?string $method): ?string
    {
        if ($method !== null && ! in_array($method, self::METHODS, true)) {
            return "Unknown compression \"{$method}\". Use lz4, or null for the server's default.";
        }

        if ($this->version() < 140000) {
            return 'Column compression needs Postgres 14 or later.';
        }

        if ($method !== null && ! in_array($method, $this->available(), true)) {
            return "This Postgres server was built without {$method}.";
        }

        return null;
    }

    /**
     * Each column and its compression: lz4, pglz, or null for the default.
     *
     * @return array<string, string|null> By "table.column"
     */
    public function columns(): array
    {
        return array_map(fn (object $column) => $column->method, $this->catalog());
    }

    /**
     * Set every column to the method, or back to the default with null. A
     * change to the catalog only, so it takes a brief lock and rewrites
     * nothing.
     *
     * @return array<int, string> The columns changed, as "table.column"
     */
    public function apply(?string $method): array
    {
        if (($reason = $this->unsupported($method)) !== null) {
            throw new RuntimeException($reason);
        }

        $changed = [];

        foreach ($this->columns() as $name => $current) {
            if ($current === $method) {
                continue;
            }

            [$table, $column] = explode('.', $name);
            $grammar = $this->connection->getQueryGrammar();

            $this->connection->statement(sprintf(
                'alter table %s alter column %s set compression %s',
                $grammar->wrapTable($table),
                $grammar->wrap($column),
                $method ?? 'default',
            ));

            $changed[] = $name;
        }

        return $changed;
    }

    /**
     * Rewrite stored values compressed some other way than the method, a
     * chunk of rows at a time in id order, until the deadline. Values small
     * enough to be stored uncompressed are left alone. Run again, it picks up
     * where it stopped, since rewritten rows no longer match.
     *
     * @param  float  $until  A microtime(true) deadline
     * @return array{rows: int, done: bool}
     */
    public function recompress(string $method, int $chunk, float $until, bool $dryRun = false): array
    {
        $rows = 0;
        $tables = [];

        foreach ($this->catalog() as $name => $column) {
            [$table, $name] = explode('.', $name);
            $tables[$table][$name] = $column->type;
        }

        foreach ($tables as $table => $columns) {
            $stale = implode(' or ', array_map(fn (string $column) => "pg_column_compression({$column}) <> ?", array_keys($columns)));
            $bindings = array_fill(0, count($columns), $method);
            // A value built anew is compressed with the column's method.
            // Assigning a column to itself would keep the stored value as is.
            $rebuilt = array_map(fn (string $type, string $column) => $this->connection->raw(
                $type === 'jsonb' ? "{$column} || '{}'::jsonb" : "cast(cast({$column} as text) as {$type})",
            ), $columns, array_keys($columns));
            $after = 0;

            do {
                if (microtime(true) >= $until) {
                    return ['rows' => $rows, 'done' => false];
                }

                // The next chunk of ids, then those of them with stale values.
                $last = $this->connection->table($table)->where('id', '>', $after)->orderBy('id')->offset($chunk - 1)->limit(1)->value('id');
                $range = $this->connection->table($table)->where('id', '>', $after)
                    ->when($last !== null, fn ($query) => $query->where('id', '<=', $last))
                    ->whereRaw("({$stale})", $bindings);

                $rows += $dryRun ? $range->count() : $range->update(array_combine(array_keys($columns), $rebuilt));
                $after = (int) $last;
            } while ($last !== null);
        }

        return ['rows' => $rows, 'done' => true];
    }

    /**
     * @return array<int, string>
     */
    protected function available(): array
    {
        $setting = $this->connection->selectOne("select enumvals from pg_settings where name = 'default_toast_compression'");

        return $setting === null ? [] : explode(',', trim((string) $setting->enumvals, '{}'));
    }

    protected function version(): int
    {
        return (int) $this->connection->selectOne("select current_setting('server_version_num') as version")->version;
    }

    /**
     * The body columns of the tables this install has, spaces only when that
     * feature migrated, with their type and compression.
     *
     * @return array<string, object{type: string, method: string|null}> By "table.column"
     */
    protected function catalog(): array
    {
        $columns = [];

        foreach (self::COLUMNS as $table => $names) {
            foreach ($names as $name) {
                $row = $this->connection->selectOne(
                    'select format_type(atttypid, atttypmod) as type, attcompression as method from pg_attribute where attrelid = to_regclass(?) and attname = ? and not attisdropped',
                    [$table, $name],
                );

                if ($row !== null) {
                    $columns["{$table}.{$name}"] = (object) [
                        'type' => (string) $row->type,
                        'method' => match ((string) $row->method) {
                            'l' => 'lz4',
                            'p' => 'pglz',
                            default => null,
                        },
                    ];
                }
            }
        }

        return $columns;
    }
}
