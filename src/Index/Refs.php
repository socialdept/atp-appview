<?php

namespace SocialDept\AtpAppview\Index;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Views\Contracts\ReadsRefs;
use SocialDept\AtpAppview\Views\View;

/**
 * Which indexed records point at which, kept in atp_record_refs for the
 * counts and viewer state of hydrated views.
 *
 * The fields that are references come from the views themselves, every Count
 * and ViewerRef part of a view in atp-appview.views or atp-appview.xrpc.views,
 * plus any listed in atp-appview.index.refs. A field may hold one at-uri or a
 * list of them, and dot paths reach nested ones (`subject.uri`).
 *
 * Rows are written from what the records index holds after a batch, so the
 * revision guard, the blocklist and tombstones apply to them too. Only live
 * records have rows, and `hidden` mirrors hidden_at, so counting never reads
 * atp_records.
 */
class Refs
{
    public const TABLE = 'atp_record_refs';

    public const PATHS_TABLE = 'atp_ref_paths';

    /** @var array<string, array<int, string>>|null */
    protected ?array $paths = null;

    /**
     * Reference fields by collection.
     *
     * @return array<string, array<int, string>>
     */
    public function paths(): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }

        $paths = [];

        foreach ((array) config('atp-appview.index.refs', []) as $collection => $fields) {
            foreach ((array) $fields as $path) {
                $paths[$collection][] = $path;
            }
        }

        foreach (self::views() as $view) {
            foreach (self::refsIn(app($view)->parts()) as [$collection, $path]) {
                $paths[$collection][] = $path;
            }
        }

        return $this->paths = array_map(fn (array $fields) => array_values(array_unique($fields)), $paths);
    }

    /**
     * Forget the paths read from config and views, after either changes.
     */
    public function flush(): void
    {
        $this->paths = null;
    }

    /**
     * The at-uris a record points at, by path.
     *
     * @param  array<string, mixed>  $value
     * @return array<int, array{path: string, target: string}>
     */
    public function extract(string $collection, array $value, ?array $only = null): array
    {
        $refs = [];

        foreach ($only ?? $this->paths()[$collection] ?? [] as $path) {
            foreach (Arr::wrap(data_get($value, $path)) as $target) {
                if (is_string($target) && str_starts_with($target, 'at://') && strlen($target) <= 512) {
                    $refs[] = ['path' => $path, 'target' => $target];
                }
            }
        }

        return $refs;
    }

    /**
     * Rewrite the references of the records a batch wrote, from the rows it
     * wrote them from. Only rows the batch actually changed are touched: the
     * writer leaves indexed_at alone for a record re-sent unchanged or older
     * than the one stored, so a resync rewrites nothing. One narrow select by
     * primary key, then a delete and an insert, and nothing at all when no
     * record is in a collection with references.
     *
     * @param  array<int, array<string, mixed>>  $rows  As RecordIndexer wrote them
     * @param  string  $now  The indexed_at the batch wrote
     */
    public function rewrite(Connection $connection, array $rows, string $now): void
    {
        $paths = $this->paths();
        $rows = array_values(array_filter($rows, fn (array $row) => isset($paths[$row['collection']])));

        foreach (array_chunk($rows, 500) as $chunk) {
            $stored = $connection->table('atp_records')->whereIn('uri', array_column($chunk, 'uri'))
                ->get(['uri', 'indexed_at', 'deleted_at', 'hidden_at'])
                ->keyBy('uri');

            $changed = [];
            $refs = [];

            foreach ($chunk as $row) {
                $current = $stored[$row['uri']] ?? null;

                if ($current === null || substr((string) $current->indexed_at, 0, 19) !== $now) {
                    continue;
                }

                $changed[] = $row['uri'];

                if ($current->deleted_at === null) {
                    array_push($refs, ...$this->rowsFor((object) [...$row, 'hidden_at' => $current->hidden_at]));
                }
            }

            if ($changed !== []) {
                $this->apply($connection, $changed, $refs);
            }
        }
    }

    /**
     * Bring these records' references to the given set, writing only what
     * differs: a new record is one insert, an edit that keeps its targets
     * writes nothing, and a delete is one delete.
     *
     * @param  array<int, string>  $uris
     * @param  array<int, array<string, mixed>>  $rows  Every reference the records should have
     */
    protected function apply(Connection $connection, array $uris, array $rows): void
    {
        $key = fn ($ref) => implode("\n", [$ref->source_uri ?? $ref['source_uri'], $ref->path ?? $ref['path'], $ref->target_uri ?? $ref['target_uri'], (int) ($ref->hidden ?? $ref['hidden'])]);

        $existing = $connection->table(self::TABLE)->whereIn('source_uri', $uris)
            ->get(['source_uri', 'path', 'target_uri', 'hidden']);

        $have = [];

        foreach ($existing as $ref) {
            $have[$ref->source_uri][$key($ref)] = true;
        }

        $want = [];

        foreach ($rows as $row) {
            $want[$row['source_uri']][$key($row)] = $row;
        }

        $replace = [];
        $insert = [];

        foreach ($uris as $uri) {
            $old = $have[$uri] ?? [];
            $new = $want[$uri] ?? [];

            // The same set of references: nothing to write.
            if (count($old) === count($new) && array_diff_key($old, $new) === []) {
                continue;
            }

            if ($old !== []) {
                $replace[] = $uri;
            }

            array_push($insert, ...array_values($new));
        }

        if ($replace !== []) {
            $connection->table(self::TABLE)->whereIn('source_uri', $replace)->delete();
        }

        $this->insert($connection, $insert);
    }

    /**
     * Hide or show the references of accounts, as their records are.
     *
     * @param  array<int, string>  $dids
     */
    public static function hide(Connection $connection, array $dids, bool $hidden = true): void
    {
        foreach (array_chunk($dids, 500) as $chunk) {
            $connection->table(self::TABLE)->whereIn('source_did', $chunk)->where('hidden', ! $hidden)->update(['hidden' => $hidden]);
        }
    }

    /**
     * Drop the references of accounts whose records were all tombstoned.
     *
     * @param  array<int, string>  $dids
     */
    public static function drop(Connection $connection, array $dids): void
    {
        foreach (array_chunk($dids, 500) as $chunk) {
            $connection->table(self::TABLE)->whereIn('source_did', $chunk)->delete();
        }
    }

    /**
     * Drop references whose record no longer exists, for accounts some of
     * whose records were deleted outright, such as by a blocklist purge.
     *
     * @param  array<int, string>  $dids
     */
    public static function dropOrphans(Connection $connection, array $dids): void
    {
        foreach (array_chunk($dids, 500) as $chunk) {
            $connection->table(self::TABLE)->whereIn('source_did', $chunk)
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('atp_records')
                    ->whereColumn('atp_records.uri', self::TABLE.'.source_uri')->whereNull('atp_records.deleted_at'))
                ->delete();
        }
    }

    /**
     * Build the rows of reference fields added since the last run, for
     * records already indexed, and drop those of fields removed. Meant for
     * the scheduler: a field is marked built only once it is complete, so an
     * interrupted run picks it up again.
     *
     * @param  (Closure(string $collection, string $path, int $done): void)|null  $progress
     * @return array{built: array<int, string>, dropped: array<int, string>}
     */
    public function work(?Closure $progress = null): array
    {
        $connection = $this->connection();
        $wanted = [];

        foreach ($this->paths() as $collection => $paths) {
            foreach ($paths as $path) {
                $wanted["{$collection}#{$path}"] = [$collection, $path];
            }
        }

        $built = $connection->table(self::PATHS_TABLE)->get(['collection', 'path'])
            ->mapWithKeys(fn ($row) => ["{$row->collection}#{$row->path}" => [$row->collection, $row->path]])
            ->all();

        foreach (array_diff_key($built, $wanted) as $key => [$collection, $path]) {
            $connection->table(self::TABLE)->where('source_collection', $collection)->where('path', $path)->delete();
            $connection->table(self::PATHS_TABLE)->where('collection', $collection)->where('path', $path)->delete();
        }

        foreach (array_diff_key($wanted, $built) as [$collection, $path]) {
            $this->build($collection, $path, $progress);
            // Ignored if another run got there first.
            $connection->table(self::PATHS_TABLE)->insertOrIgnore(['collection' => $collection, 'path' => $path, 'built_at' => Carbon::now('UTC')]);
        }

        return ['built' => array_keys(array_diff_key($wanted, $built)), 'dropped' => array_keys(array_diff_key($built, $wanted))];
    }

    /**
     * Write one field's references for every live record in a collection,
     * walking the collection by primary key a thousand records at a time.
     *
     * @param  (Closure(string $collection, string $path, int $done): void)|null  $progress
     */
    public function build(string $collection, string $path, ?Closure $progress = null): void
    {
        $connection = $this->connection();
        $after = '';
        $done = 0;

        do {
            $records = $connection->table('atp_records')
                ->where('collection', $collection)->whereNull('deleted_at')->where('uri', '>', $after)
                ->orderBy('uri')->limit(1000)
                ->get(['uri', 'did', 'collection', 'record', 'hidden_at']);

            $this->insert($connection, $records->flatMap(fn ($record) => $this->rowsFor($record, [$path]))->all());

            $after = (string) $records->last()?->uri;
            $done += $records->count();

            if ($progress !== null && $records->isNotEmpty()) {
                $progress($collection, $path, $done);
            }
        } while ($records->count() === 1000);
    }

    /**
     * References whose source record is visible, for counts and viewer state.
     */
    public function visible(): Builder
    {
        return $this->connection()->table(self::TABLE)->where('hidden', false);
    }

    /**
     * Every view whose references are kept.
     *
     * @return array<int, class-string<View>>
     */
    public static function views(): array
    {
        return array_values(array_unique([
            ...(array) config('atp-appview.views', []),
            ...array_values((array) config('atp-appview.xrpc.views', [])),
        ]));
    }

    /**
     * The [collection, path] pairs the parts read from atp_record_refs.
     *
     * @param  array<string, mixed>  $parts
     * @return array<int, array{0: string, 1: string}>
     */
    protected static function refsIn(array $parts): array
    {
        $refs = [];

        foreach ($parts as $part) {
            if (is_array($part)) {
                array_push($refs, ...self::refsIn($part));
            } elseif ($part instanceof ReadsRefs) {
                $refs[] = $part->ref();
            }
        }

        return $refs;
    }

    /**
     * @param  array<int, string>|null  $only  Just these paths
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFor(object $record, ?array $only = null): array
    {
        $value = is_string($record->record) ? (json_decode($record->record, true) ?: []) : (array) $record->record;

        return array_map(fn (array $ref) => [
            'source_uri' => $record->uri,
            'source_did' => $record->did,
            'source_collection' => $record->collection,
            'path' => $ref['path'],
            'target_uri' => $ref['target'],
            'hidden' => $record->hidden_at !== null,
        ], $this->extract($record->collection, $value, $only));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function insert(Connection $connection, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->table(self::TABLE)->insertOrIgnore($chunk);
        }
    }

    protected static function collectionOf(string $uri): string
    {
        return explode('/', substr($uri, 5), 3)[1] ?? '';
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
