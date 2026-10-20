<?php

namespace SocialDept\AtpAppview\Index;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Views\Contracts\ReadsRefs;

/**
 * The values of indexed fields, kept in atp_record_values, and how many
 * visible records have each, kept in atp_value_counts.
 *
 * A field is declared in atp-appview.fields, or comes from a view: every
 * Count and ViewerRef part names a reference, which is a field holding an
 * at-uri. Each (collection, field) is a small id in atp_index_keys.
 *
 * Rows are written from what the records index holds after a batch, so the
 * revision guard, the blocklist and tombstones apply to them. Only live
 * records have rows, and `hidden` mirrors hidden_at and moderated_at, so lists and counts
 * never read atp_records. Values are stored as 8-byte hashes, matched
 * exactly, and dates and numbers are kept as well, to sort.
 */
class RecordValues
{
    public const VALUES = 'atp_record_values';

    public const COUNTS = 'atp_value_counts';

    public const KEYS = 'atp_index_keys';

    /** @var array<string, array<string, Field>>|null */
    protected ?array $fields = null;

    /** @var array<string, int>|null Key id by "collection#field" */
    protected ?array $keys = null;

    /**
     * Indexed fields by collection, then by name.
     *
     * @return array<string, array<string, Field>>
     */
    public function fields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $fields = [];

        foreach ((array) config('atp-appview.fields', []) as $collection => $declared) {
            foreach ((array) $declared as $name => $type) {
                $fields[$collection][$name] = Field::declared($collection, (string) $name, $type);
            }
        }

        // References: those named in atp-appview.index.refs before fields
        // existed, and those the views count.
        foreach ((array) config('atp-appview.index.refs', []) as $collection => $paths) {
            foreach ((array) $paths as $path) {
                $fields[$collection][$path] ??= new Field($collection, $path, 'uri', $path, many: true);
            }
        }

        // The fields follows and blocks name their subject in, for the graph.
        foreach ([Graph::FOLLOWS, Graph::BLOCKS] as $kind) {
            if (($relation = Graph::relation($kind)) !== null) {
                $fields[$relation['collection']][$relation['path']] ??= new Field($relation['collection'], $relation['path'], 'uri', $relation['path'], many: true);
            }
        }

        foreach (self::views() as $view) {
            foreach (self::refsIn(app($view)->parts()) as [$collection, $path]) {
                $fields[$collection][$path] ??= new Field($collection, $path, 'uri', $path, many: true);
            }
        }

        return $this->fields = $fields;
    }

    public function field(string $collection, string $name): ?Field
    {
        return $this->fields()[$collection][$name] ?? null;
    }

    /**
     * Forget fields and keys read earlier, after config or views change.
     */
    public function flush(): void
    {
        $this->fields = null;
        $this->keys = null;
    }

    /**
     * The small id of a field, created the first time it is needed.
     */
    public function keyId(Field $field): int
    {
        $name = "{$field->collection}#{$field->name}";
        $this->keys ??= $this->loadKeys();

        if (! isset($this->keys[$name])) {
            $this->connection()->table(self::KEYS)->insertOrIgnore([
                'collection' => $field->collection,
                'field' => $field->name,
                'type' => $field->signature(),
            ]);
            $this->keys = $this->loadKeys();
        }

        return $this->keys[$name];
    }

    /**
     * Rewrite the values of the records a batch wrote, from the rows it wrote
     * them from. Only records the batch changed are touched: the writer leaves
     * indexed_at alone for a record re-sent unchanged or older than the one
     * stored, so a resync writes nothing. Only what differs is written, and
     * counts move with it.
     *
     * @param  array<int, array<string, mixed>>  $rows  As RecordIndexer wrote them
     * @param  string  $now  The indexed_at the batch wrote
     */
    public function rewrite(Connection $connection, array $rows, string $now): void
    {
        $fields = $this->fields();
        $rows = array_values(array_filter($rows, fn (array $row) => isset($fields[$row['collection']])));

        foreach (array_chunk($rows, 500) as $chunk) {
            $stored = $connection->table('atp_records')->whereIn('uri', array_column($chunk, 'uri'))
                ->get(['id', 'uri', 'did', 'indexed_at', 'deleted_at', 'hidden_at', 'moderated_at', 'limited_at', 'sort_at'])
                ->keyBy('uri');

            $want = [];
            $texts = [];

            foreach ($chunk as $row) {
                $current = $stored[$row['uri']] ?? null;

                if ($current === null || substr((string) $current->indexed_at, 0, 19) !== $now) {
                    continue;
                }

                $want[$current->id] = $current->deleted_at === null
                    ? $this->rowsFor($current, $row['collection'], self::decode($row['record']), $texts)
                    : [];
            }

            if ($want !== []) {
                $this->apply($connection, $want, $texts);
            }
        }
    }

    /**
     * Hide or show the values of accounts, as their records are, moving
     * counts with them.
     *
     * @param  array<int, string>  $dids
     */
    public function hide(Connection $connection, array $dids, bool $hidden = true): void
    {
        foreach (array_chunk($dids, 500) as $chunk) {
            // Showing an account's records again leaves those moderation hides.
            $affected = (
                $hidden
                ? $this->ofAccounts($connection, $chunk)
                : $connection->table(self::VALUES)->whereIn('record_id', $this->recordIdsOf($connection, $chunk)->whereNull('moderated_at'))
            )->where('hidden', ! $hidden);

            $this->adjust($connection, (clone $affected)->groupBy('key_id', 'value_hash')
                ->selectRaw('key_id, value_hash, count(*) as n')->get()
                ->map(fn ($row) => [(int) $row->key_id, (int) $row->value_hash, $hidden ? -(int) $row->n : (int) $row->n])
                ->all());

            $affected->update(['hidden' => $hidden]);
        }
    }

    /**
     * Hide or show the values of single records, as moderation hides them,
     * moving counts with them. Showing leaves records their account's status
     * hides.
     *
     * @param  array<int, int>  $ids
     */
    public function hideRecords(Connection $connection, array $ids, bool $hidden = true): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $affected = (
                $hidden
                ? $connection->table(self::VALUES)->whereIn('record_id', $chunk)
                : $connection->table(self::VALUES)->whereIn('record_id', $connection->table('atp_records')->whereIn('id', $chunk)->whereNull('hidden_at')->select('id'))
            )->where('hidden', ! $hidden);

            $this->adjust($connection, (clone $affected)->groupBy('key_id', 'value_hash')
                ->selectRaw('key_id, value_hash, count(*) as n')->get()
                ->map(fn ($row) => [(int) $row->key_id, (int) $row->value_hash, $hidden ? -(int) $row->n : (int) $row->n])
                ->all());

            $affected->update(['hidden' => $hidden]);
        }
    }

    /**
     * Mark the values of single records as needing review, or not, so
     * discovery leaves them out. Counts are unchanged: the records are still
     * shown where asked for directly.
     *
     * @param  array<int, int>  $ids
     */
    public function limitRecords(Connection $connection, array $ids, bool $limited = true): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $connection->table(self::VALUES)->whereIn('record_id', $chunk)->where('limited', ! $limited)->update(['limited' => $limited]);
        }
    }

    /**
     * Drop the values of accounts whose records were all tombstoned.
     *
     * @param  array<int, string>  $dids
     */
    public function drop(Connection $connection, array $dids): void
    {
        foreach (array_chunk($dids, 500) as $chunk) {
            $this->adjust($connection, $this->visibleTotals($this->ofAccounts($connection, $chunk)));
            $this->ofAccounts($connection, $chunk)->delete();
        }
    }

    /**
     * Drop the values of records deleted outright, such as by a blocklist
     * purge, by the ids they had.
     *
     * @param  array<int, int>  $ids
     */
    public function dropRecords(Connection $connection, array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = fn () => $connection->table(self::VALUES)->whereIn('record_id', $chunk);

            $this->adjust($connection, $this->visibleTotals($rows()));
            $rows()->delete();
        }
    }

    /**
     * Build the values of fields declared since the last run, for records
     * already indexed, and drop those of fields removed or retyped. Meant for
     * the scheduler: a field is marked built only once it is complete, so an
     * interrupted run picks it up again.
     *
     * @param  (Closure(Field $field, int $done): void)|null  $progress
     * @return array{built: array<int, string>, dropped: array<int, string>}
     */
    public function work(?Closure $progress = null): array
    {
        $connection = $this->connection();
        $declared = [];

        foreach ($this->fields() as $collection => $fields) {
            foreach ($fields as $field) {
                $declared["{$collection}#{$field->name}"] = $field;
            }
        }

        $dropped = [];

        foreach ($connection->table(self::KEYS)->get() as $key) {
            $name = "{$key->collection}#{$key->field}";
            $field = $declared[$name] ?? null;

            // Gone, or retyped, so its values no longer match how they are read.
            if ($field === null || $key->type !== $field->signature()) {
                $connection->table(self::VALUES)->where('key_id', $key->id)->delete();
                $connection->table(self::COUNTS)->where('key_id', $key->id)->delete();
                $connection->table(self::KEYS)->where('id', $key->id)->delete();
                $dropped[] = $name;
            }
        }

        $this->keys = null;
        $ready = $connection->table(self::KEYS)->whereNotNull('built_at')->get()
            ->map(fn ($key) => "{$key->collection}#{$key->field}")->flip()->all();
        $built = [];

        foreach (array_diff_key($declared, $ready) as $name => $field) {
            $this->build($field, $progress);
            $connection->table(self::KEYS)->where('id', $this->keyId($field))->update(['built_at' => Carbon::now('UTC')]);
            $built[] = $name;
        }

        return ['built' => $built, 'dropped' => $dropped];
    }

    /**
     * Write one field's values for every live record in its collection, a
     * thousand records at a time by id, then count them. Ingest may write the
     * same rows meanwhile, which the unique index keeps once, so the counts
     * are taken from the rows at the end rather than as they are written.
     *
     * @param  (Closure(Field $field, int $done): void)|null  $progress
     */
    public function build(Field $field, ?Closure $progress = null): void
    {
        $connection = $this->connection();
        $key = $this->keyId($field);
        $connection->table(self::VALUES)->where('key_id', $key)->delete();
        $connection->table(self::COUNTS)->where('key_id', $key)->delete();
        $after = 0;
        $done = 0;
        $texts = [];

        do {
            $records = $connection->table('atp_records')
                ->where('collection', $field->collection)->whereNull('deleted_at')->where('id', '>', $after)
                ->orderBy('id')->limit(1000)
                ->get(['id', 'did', 'record', 'hidden_at', 'moderated_at', 'limited_at', 'sort_at']);

            $rows = [];

            foreach ($records as $record) {
                array_push($rows, ...$this->rowsFor($record, $field->collection, self::decode($record->record), $texts, [$field]));
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $connection->table(self::VALUES)->insertOrIgnore($chunk);
            }

            $after = (int) $records->last()?->id;
            $done += $records->count();

            if ($progress !== null && $records->isNotEmpty()) {
                $progress($field, $done);
            }
        } while ($records->count() === 1000);

        $connection->transaction(function () use ($connection, $key, $texts) {
            $connection->table(self::COUNTS)->where('key_id', $key)->delete();
            $this->adjust($connection, $connection->table(self::VALUES)->where('key_id', $key)->where('hidden', false)
                ->groupBy('key_id', 'value_hash')->selectRaw('key_id, value_hash, count(*) as n')->get()
                ->map(fn ($row) => [(int) $row->key_id, (int) $row->value_hash, (int) $row->n])->all(), $texts);
        });
    }

    /**
     * Count every value again from atp_record_values, to repair counts that
     * drifted. Each value's text is kept from before.
     */
    public function recount(): void
    {
        $connection = $this->connection();

        $connection->transaction(function () use ($connection) {
            $connection->table(self::COUNTS)->update(['total' => 0]);
            $this->adjust($connection, $connection->table(self::VALUES)->where('hidden', false)
                ->groupBy('key_id', 'value_hash')->selectRaw('key_id, value_hash, count(*) as n')->get()
                ->map(fn ($row) => [(int) $row->key_id, (int) $row->value_hash, (int) $row->n])->all());
        });
    }

    /**
     * Values of visible records, for filters, sorts and viewer state.
     */
    public function visible(): Builder
    {
        return $this->connection()->table(self::VALUES)->where('hidden', false);
    }

    /**
     * Values of visible records under an alias, for queries that join them.
     */
    public function visibleAs(string $alias): Builder
    {
        return $this->connection()->table(self::VALUES." as {$alias}")->where("{$alias}.hidden", false);
    }

    /**
     * Counts of visible records by value hash, for the (key, hash) pairs asked.
     *
     * @param  array<int, int>  $keys
     * @param  array<int, int>  $hashes
     * @return array<string, int> By "key#hash"
     */
    public function totals(array $keys, array $hashes): array
    {
        if ($keys === [] || $hashes === []) {
            return [];
        }

        return $this->connection()->table(self::COUNTS)
            ->whereIn('key_id', array_values(array_unique($keys)))
            ->whereIn('value_hash', array_values(array_unique($hashes)))
            ->get(['key_id', 'value_hash', 'total'])
            ->mapWithKeys(fn ($row) => ["{$row->key_id}#{$row->value_hash}" => (int) $row->total])
            ->all();
    }

    /**
     * A field's most common values among visible records, such as top tags.
     *
     * With $since, only records sorted at or after it count, such as what is
     * trending this week, and records left out of discovery are skipped.
     * That reads atp_record_values instead of the kept totals, so keep the
     * window short on a large index.
     *
     * @return array<int, array{value: string|null, total: int}>
     */
    public function top(string $collection, string $field, int $limit = 20, ?\DateTimeInterface $since = null): array
    {
        $declared = $this->field($collection, $field)
            ?? throw new \InvalidArgumentException("{$field} is not an indexed field of {$collection}.");
        $key = $this->keyId($declared);

        if ($since === null) {
            return $this->connection()->table(self::COUNTS)
                ->where('key_id', $key)->where('total', '>', 0)
                ->orderByDesc('total')->limit($limit)
                ->get(['value', 'total'])
                ->map(fn ($row) => ['value' => $row->value, 'total' => (int) $row->total])
                ->all();
        }

        $top = $this->visible()
            ->where('key_id', $key)->where('limited', false)
            ->where('sort_at', '>=', Carbon::instance($since)->utc()->format('Y-m-d H:i:s.u'))
            ->groupBy('value_hash')
            ->select('value_hash')->selectRaw('count(*) as total')
            ->orderByDesc('total')->orderBy('value_hash')->limit($limit)
            ->get();

        $values = $this->connection()->table(self::COUNTS)
            ->where('key_id', $key)->whereIn('value_hash', $top->pluck('value_hash')->all())
            ->pluck('value', 'value_hash');

        return $top->map(fn ($row) => ['value' => $values[$row->value_hash] ?? null, 'total' => (int) $row->total])->all();
    }

    /**
     * The latest few accounts whose visible, discoverable records hold each of
     * these values, newest first, such as who set a trending emoji. Reads one
     * bounded page of the newest matches, so a value whose recent records all
     * come from one account may list fewer.
     *
     * @param  array<int, mixed>  $values
     * @return array<string, array<int, string>> DIDs by value, as asked
     */
    public function authors(string $collection, string $field, array $values, int $per = 3, ?\DateTimeInterface $since = null): array
    {
        $declared = $this->field($collection, $field)
            ?? throw new \InvalidArgumentException("{$field} is not an indexed field of {$collection}.");
        $byHash = [];

        foreach ($values as $value) {
            if (($hash = $declared->hashOf($value)) !== null) {
                $byHash[$hash] = (string) $value;
            }
        }

        if ($byHash === []) {
            return [];
        }

        $rows = $this->visibleAs('v')
            ->join('atp_records as r', 'r.id', '=', 'v.record_id')
            ->where('v.key_id', $this->keyId($declared))->where('v.limited', false)
            ->whereIn('v.value_hash', array_keys($byHash))
            ->when($since !== null, fn ($query) => $query->where('v.sort_at', '>=', Carbon::instance($since)->utc()->format('Y-m-d H:i:s.u')))
            ->orderByDesc('v.sort_at')
            ->limit(count($byHash) * $per * 10)
            ->get(['v.value_hash', 'r.did']);

        $authors = array_fill_keys(array_values($byHash), []);

        foreach ($rows as $row) {
            $value = $byHash[(int) $row->value_hash];

            if (count($authors[$value]) < $per && ! in_array($row->did, $authors[$value], true)) {
                $authors[$value][] = $row->did;
            }
        }

        return $authors;
    }

    /**
     * Values of a field starting with what someone has typed, most common
     * first, for suggestions such as tags. Reads atp_value_counts, which holds
     * each distinct value once.
     *
     * @return array<int, array{value: string|null, total: int}>
     */
    public function suggest(string $collection, string $field, string $prefix, int $limit = 10): array
    {
        $declared = $this->field($collection, $field)
            ?? throw new \InvalidArgumentException("{$field} is not an indexed field of {$collection}.");
        $prefix = $declared->type === 'string' && ! $declared->exactCase ? mb_strtolower(trim($prefix)) : trim($prefix);

        if ($prefix === '') {
            return $this->top($collection, $field, $limit);
        }

        return $this->connection()->table(self::COUNTS)
            ->where('key_id', $this->keyId($declared))->where('total', '>', 0)
            // Read in order of use, so the most common matches come first and
            // the read stops at the limit.
            ->whereRaw("value like ? escape '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%'])
            ->orderByDesc('total')->limit($limit)
            ->get(['value', 'total'])
            ->map(fn ($row) => ['value' => $row->value, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * Every view whose references are kept.
     *
     * @return array<int, class-string>
     */
    public static function views(): array
    {
        $mapped = array_map(fn ($view) => is_array($view) ? $view['view'] : $view, array_values((array) config('atp-appview.xrpc.views', [])));

        return array_values(array_unique([...(array) config('atp-appview.views', []), ...$mapped]));
    }

    /**
     * The [collection, path] pairs the parts read.
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
     * The value rows a record should have.
     *
     * @param  array<string, mixed>  $value  The record's body
     * @param  array<string, string>  $texts  Collects each value's text for counts, by "key#hash"
     * @param  array<int, Field>|null  $only  Just these fields
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFor(object $record, string $collection, array $value, array &$texts, ?array $only = null): array
    {
        $rows = [];
        $didHash = ValueHash::of($record->did);
        // Hidden for its account's status, or by moderation.
        $hidden = $record->hidden_at !== null || ($record->moderated_at ?? null) !== null;


        foreach ($only ?? $this->fields()[$collection] ?? [] as $field) {
            $key = $this->keyId($field);

            foreach ($field->values($value) as $item) {
                $texts["{$key}#{$item['hash']}"] ??= mb_substr($item['text'], 0, 255);
                $rows[] = [
                    'key_id' => $key,
                    'record_id' => (int) $record->id,
                    // Only references need their author, for viewer state.
                    'did_hash' => $field->type === 'uri' ? $didHash : null,
                    'value_hash' => $item['hash'],
                    'value_time' => $item['time'],
                    'value_num' => $item['num'],
                    'sort_at' => $record->sort_at,
                    'hidden' => $hidden,
                    'limited' => ($record->limited_at ?? null) !== null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Bring these records' values to the given sets, writing only records
     * whose values changed, and moving counts by the difference.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $want  Rows by record id
     * @param  array<string, string>  $texts
     */
    protected function apply(Connection $connection, array $want, array $texts): void
    {
        $have = [];

        foreach ($connection->table(self::VALUES)->whereIn('record_id', array_keys($want))->get() as $row) {
            $have[(int) $row->record_id][] = (array) $row;
        }

        $replace = [];
        $insert = [];
        $deltas = [];

        foreach ($want as $id => $rows) {
            $old = $have[$id] ?? [];

            if (self::signature($old) === self::signature($rows)) {
                continue;
            }

            if ($old !== []) {
                $replace[] = $id;

                foreach ($old as $row) {
                    if (! $row['hidden']) {
                        $deltas[] = [(int) $row['key_id'], (int) $row['value_hash'], -1];
                    }
                }
            }

            foreach ($rows as $row) {
                $insert[] = $row;

                if (! $row['hidden']) {
                    $deltas[] = [$row['key_id'], $row['value_hash'], 1];
                }
            }
        }

        if ($replace !== []) {
            $connection->table(self::VALUES)->whereIn('record_id', $replace)->delete();
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            $connection->table(self::VALUES)->insertOrIgnore($chunk);
        }

        $this->adjust($connection, $deltas, $texts);
    }

    /**
     * Move counts, one statement per 500 values, atomically, so concurrent
     * batches cannot lose each other's changes.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $deltas  [key, hash, change]
     * @param  array<string, string>  $texts  Each value's text, for a count seen the first time
     */
    protected function adjust(Connection $connection, array $deltas, array $texts = []): void
    {
        $sums = [];

        foreach ($deltas as [$key, $hash, $change]) {
            $sums["{$key}#{$hash}"] = ($sums["{$key}#{$hash}"] ?? 0) + $change;
        }

        $rows = [];

        foreach (array_filter($sums) as $name => $total) {
            [$key, $hash] = explode('#', $name);
            $rows[] = ['key_id' => (int) $key, 'value_hash' => (int) $hash, 'value' => $texts[$name] ?? null, 'total' => $total];
        }

        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $add = $mysql ? 'total + values(total)' : self::COUNTS.'.total + excluded.total';
        $text = $mysql ? 'coalesce(value, values(value))' : 'coalesce('.self::COUNTS.'.value, excluded.value)';

        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->table(self::COUNTS)->upsert($chunk, ['key_id', 'value_hash'], [
                'total' => DB::raw($add),
                'value' => DB::raw($text),
            ]);
        }
    }

    /**
     * Every value of these accounts' records, found through atp_records,
     * whose (did, collection) index answers by account.
     *
     * @param  array<int, string>  $dids
     */
    protected function ofAccounts(Connection $connection, array $dids): Builder
    {
        return $connection->table(self::VALUES)->whereIn('record_id', $this->recordIdsOf($connection, $dids));
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function recordIdsOf(Connection $connection, array $dids): Builder
    {
        return $connection->table('atp_records')->whereIn('did', $dids)->select('id');
    }

    /**
     * Visible values grouped, as negative changes, for rows about to go.
     *
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    protected function visibleTotals(Builder $rows): array
    {
        return $rows->where('hidden', false)->groupBy('key_id', 'value_hash')
            ->selectRaw('key_id, value_hash, count(*) as n')->get()
            ->map(fn ($row) => [(int) $row->key_id, (int) $row->value_hash, -(int) $row->n])
            ->all();
    }

    /**
     * A record's value rows as one comparable string, ignoring order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected static function signature(array $rows): string
    {
        $parts = array_map(fn (array $row) => implode('|', [
            $row['key_id'], $row['value_hash'], (int) (bool) $row['hidden'], (string) $row['sort_at'],
        ]), $rows);
        sort($parts);

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(mixed $record): array
    {
        return is_string($record) ? (json_decode($record, true) ?: []) : (array) $record;
    }

    /**
     * @return array<string, int>
     */
    protected function loadKeys(): array
    {
        return $this->connection()->table(self::KEYS)->get(['id', 'collection', 'field'])
            ->mapWithKeys(fn ($key) => ["{$key->collection}#{$key->field}" => (int) $key->id])
            ->all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
