<?php

namespace SocialDept\AtpAppview\Spaces;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\Field;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;

/**
 * The values of indexed fields for space records, kept in
 * atp_space_record_values the way RecordValues keeps public ones, so
 * SpaceRecordQuery can filter and sort by them and record filters such as
 * muted words can read them.
 *
 * The fields are the ones RecordValues declares, under the same key ids.
 * Only live records have rows. No counts are kept: a space is read one at a
 * time, and counting its rows is cheap next to keeping totals per space.
 */
class SpaceRecordValues
{
    public const TABLE = 'atp_space_record_values';

    public function __construct(protected RecordValues $values)
    {
    }

    /**
     * Rewrite the values of the space records a batch wrote, from the rows
     * it wrote them from. As for public records, only records the batch
     * changed are touched, and only records whose values differ are written.
     *
     * @param  array<int, array<string, mixed>>  $rows  As SpaceRecordIndexer wrote them
     * @param  string  $now  The indexed_at the batch wrote
     */
    public function rewrite(Connection $connection, array $rows, string $now): void
    {
        $fields = $this->values->fields();
        $rows = array_values(array_filter($rows, fn (array $row) => isset($fields[$row['collection']])));

        foreach (array_chunk($rows, 500) as $chunk) {
            $stored = $connection->table(SpaceRecordIndexer::TABLE)->whereIn('uri', array_column($chunk, 'uri'))
                ->get(['id', 'uri', 'space', 'cid', 'indexed_at', 'deleted_at', 'sort_at'])
                ->keyBy('uri');

            $want = [];

            foreach ($chunk as $row) {
                $current = $stored[$row['uri']] ?? null;

                // Left alone by the revision guard. indexed_at alone cannot
                // say, when an older revision arrives within the same second.
                if ($current === null || substr((string) $current->indexed_at, 0, 19) !== $now || $current->cid !== $row['cid']) {
                    continue;
                }

                $want[(int) $current->id] = $current->deleted_at === null
                    ? $this->rowsFor($current, $row['collection'], RecordValues::decode($row['record']))
                    : [];
            }

            if ($want !== []) {
                $this->apply($connection, $want);
            }
        }
    }

    /**
     * Drop every value of a space, when it is unfollowed or deleted.
     */
    public function forget(Connection $connection, string $space): void
    {
        $connection->table(self::TABLE)->where('space_hash', ValueHash::of($space))->delete();
    }

    /**
     * Build the values of fields not yet built for space records, and drop
     * those of fields RecordValues no longer has a key for. Run after
     * RecordValues::work(), which settles the keys. A field is marked built
     * only once it is complete, so an interrupted run picks it up again.
     *
     * @param  (Closure(Field $field, int $done): void)|null  $progress
     * @return array<int, string> The fields built, as "collection#field"
     */
    public function work(?Closure $progress = null): array
    {
        $connection = $this->connection();

        $connection->table(self::TABLE)
            ->whereNotIn('key_id', fn ($keys) => $keys->select('id')->from(RecordValues::KEYS))
            ->delete();

        $pending = $connection->table(RecordValues::KEYS)->whereNull('spaces_built_at')->get(['id', 'collection', 'field']);
        $built = [];

        foreach ($pending as $key) {
            $field = $this->values->field($key->collection, $key->field);

            if ($field === null) {
                continue;
            }

            $this->build($field, $progress);
            $connection->table(RecordValues::KEYS)->where('id', $key->id)->update(['spaces_built_at' => Carbon::now('UTC')]);
            $built[] = "{$key->collection}#{$key->field}";
        }

        return $built;
    }

    /**
     * Write one field's values for every live space record in its
     * collection, a thousand records at a time by id.
     *
     * @param  (Closure(Field $field, int $done): void)|null  $progress
     */
    public function build(Field $field, ?Closure $progress = null): void
    {
        $connection = $this->connection();
        $connection->table(self::TABLE)->where('key_id', $this->values->keyId($field))->delete();
        $after = 0;
        $done = 0;

        do {
            $records = $connection->table(SpaceRecordIndexer::TABLE)
                ->where('collection', $field->collection)->whereNull('deleted_at')->where('id', '>', $after)
                ->orderBy('id')->limit(1000)
                ->get(['id', 'space', 'record', 'sort_at']);

            $rows = [];

            foreach ($records as $record) {
                array_push($rows, ...$this->rowsFor($record, $field->collection, RecordValues::decode($record->record), [$field]));
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $connection->table(self::TABLE)->insertOrIgnore($chunk);
            }

            $after = (int) $records->last()?->id;
            $done += $records->count();

            if ($progress !== null && $records->isNotEmpty()) {
                $progress($field, $done);
            }
        } while ($records->count() === 1000);
    }

    /**
     * The value rows a space record should have.
     *
     * @param  array<string, mixed>  $value  The record's body
     * @param  array<int, Field>|null  $only  Just these fields
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFor(object $record, string $collection, array $value, ?array $only = null): array
    {
        $space = ValueHash::of($record->space);

        return array_map(fn (array $item) => [
            'key_id' => $item['key'],
            'record_id' => (int) $record->id,
            'space_hash' => $space,
            'value_hash' => $item['hash'],
            'value_time' => $item['time'],
            'value_num' => $item['num'],
            'sort_at' => $record->sort_at,
        ], $this->values->extract($collection, $value, $only));
    }

    /**
     * Bring these records' values to the given sets, writing only records
     * whose values changed.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $want  Rows by record id
     */
    protected function apply(Connection $connection, array $want): void
    {
        $have = [];

        foreach ($connection->table(self::TABLE)->whereIn('record_id', array_keys($want))->get() as $row) {
            $have[(int) $row->record_id][] = (array) $row;
        }

        $replace = [];
        $insert = [];

        foreach ($want as $id => $rows) {
            $old = $have[$id] ?? [];

            if (self::signature($old) === self::signature($rows)) {
                continue;
            }

            if ($old !== []) {
                $replace[] = $id;
            }

            array_push($insert, ...$rows);
        }

        if ($replace !== []) {
            $connection->table(self::TABLE)->whereIn('record_id', $replace)->delete();
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            $connection->table(self::TABLE)->insertOrIgnore($chunk);
        }
    }

    /**
     * A record's value rows as one comparable string, ignoring order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected static function signature(array $rows): string
    {
        $parts = array_map(fn (array $row) => implode('|', [
            $row['key_id'], $row['value_hash'], (string) $row['sort_at'],
        ]), $rows);
        sort($parts);

        return implode("\n", $parts);
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
