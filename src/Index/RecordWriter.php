<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * Writes record rows with a revision guard: a row is only overwritten by one
 * whose rev is newer, or whose rev is the same and whose CID differs.
 *
 * An unchanged record sent again at the same rev, as Tap does when it resyncs
 * a repo, leaves the row alone, so indexed_at and sort_at keep their values.
 * A delete at the same rev still applies, since its CID is null.
 *
 * A rev is the repo commit's revision, which the repository spec defines as a
 * TID that increases monotonically within one repo. Every TID is 13
 * characters from an alphabet already in ASCII order, so byte order is time
 * order, which is why the rev column uses a binary collation. Comparisons are
 * only ever between versions of the same record, so they stay within a repo.
 *
 * Laravel's upsert() cannot express the condition, so the statement is built
 * per database.
 */
class RecordWriter
{
    /**
     * Columns in the order every row supplies them. cid and rev stay last: on
     * MySQL each assignment's condition reads the stored cid and rev, so they
     * must change after every other column.
     */
    public const COLUMNS = ['uri', 'did', 'collection', 'rkey', 'record', 'repair', 'indexed_at', 'sort_at', 'deleted_at', 'cid', 'rev'];

    /**
     * Rows per statement, well under every driver's bound parameter limit.
     */
    public const CHUNK = 500;

    public function __construct(
        protected Connection $connection,
        protected string $table = 'atp_records',
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Keyed by COLUMNS, at most one per uri
     */
    public function upsert(array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->connection->statement($this->sql(count($chunk)), $this->bindings($chunk));
        }
    }

    protected function sql(int $rows): string
    {
        $grammar = $this->connection->getQueryGrammar();
        $table = $grammar->wrapTable($this->table);
        $columns = implode(', ', array_map(fn ($column) => $grammar->wrap($column), self::COLUMNS));
        $values = implode(', ', array_fill(0, $rows, '('.implode(', ', array_fill(0, count(self::COLUMNS), '?')).')'));
        $insert = "insert into {$table} ({$columns}) values {$values}";

        return match ($this->connection->getDriverName()) {
            'pgsql', 'sqlite' => $insert.' '.$this->onConflict($table),
            'mysql', 'mariadb' => $insert.' '.$this->onDuplicateKey(),
            default => throw new InvalidArgumentException("The record index does not support the {$this->connection->getDriverName()} driver."),
        };
    }

    protected function onConflict(string $table): string
    {
        $grammar = $this->connection->getQueryGrammar();
        $rev = $grammar->wrap('rev');

        $assignments = implode(', ', array_map(
            fn ($column) => $grammar->wrap($column).' = excluded.'.$grammar->wrap($column),
            array_slice(self::COLUMNS, 1),
        ));

        $cid = $grammar->wrap('cid');
        $distinct = $this->connection->getDriverName() === 'sqlite' ? 'is not' : 'is distinct from';

        return "on conflict ({$grammar->wrap('uri')}) do update set {$assignments} "
            ."where excluded.{$rev} > {$table}.{$rev} "
            ."or (excluded.{$rev} = {$table}.{$rev} and excluded.{$cid} {$distinct} {$table}.{$cid})";
    }

    protected function onDuplicateKey(): string
    {
        $grammar = $this->connection->getQueryGrammar();
        $rev = $grammar->wrap('rev');

        $cid = $grammar->wrap('cid');
        $newer = "values({$rev}) > {$rev} or (values({$rev}) = {$rev} and not (values({$cid}) <=> {$cid}))";

        $assignments = implode(', ', array_map(function ($column) use ($grammar, $newer) {
            $wrapped = $grammar->wrap($column);

            return "{$wrapped} = if({$newer}, values({$wrapped}), {$wrapped})";
        }, array_slice(self::COLUMNS, 1)));

        return "on duplicate key update {$assignments}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    protected function bindings(array $rows): array
    {
        $bindings = [];

        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        return $bindings;
    }
}
