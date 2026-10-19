<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SocialDept\AtpAppview\Models\Record;

/**
 * A page of a collection's records, filtered and sorted by indexed fields,
 * read from atp_record_values alone and then fetched by id.
 *
 *     RecordQuery::in('com.example.post')
 *         ->where('tags', 'php')
 *         ->orderBy('createdAt')
 *         ->after($cursor)
 *         ->limit(50)
 *         ->get();
 *
 * Without orderBy() records come newest first by sort_at. Sorting by a field
 * leaves out records that have no value for it. Pages are keyset based, so
 * they never skip or repeat a record while new ones arrive.
 */
class RecordQuery
{
    /**
     * Above this many matches, a filter paired with a sort is checked against
     * the sort's index rather than read in full and sorted. Reading and sorting
     * costs in step with the matches, about 50 ms for 17,000 on Postgres.
     * Walking the sort's index is quick only when the matches happen to sort
     * early, which nothing predicts, so it is kept for very common values.
     */
    public static int $sortFirstAbove = 50000;

    /** @var array<int, array{key: int, hash: int|null, field: Field}> */
    protected array $filters = [];

    protected ?Field $sort = null;

    protected string $direction = 'desc';

    protected ?string $did = null;

    protected ?string $cursor = null;

    protected int $limit = 50;

    protected RecordValues $values;

    public function __construct(protected string $collection)
    {
        $this->values = app(RecordValues::class);
    }

    public static function in(string $collection): static
    {
        return new static($collection);
    }

    /**
     * Only records whose field has this value. Several are all required.
     */
    public function where(string $field, mixed $value): static
    {
        $declared = $this->field($field);
        $this->filters[] = ['key' => $this->values->keyId($declared), 'hash' => $declared->hashOf($value), 'field' => $declared];

        return $this;
    }

    /**
     * Only records in one repo.
     */
    public function by(string $did): static
    {
        $this->did = $did;

        return $this;
    }

    /**
     * Order by a date or number field, or by sort_at when null.
     */
    public function orderBy(?string $field = null, string $direction = 'desc'): static
    {
        $this->direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        if ($field === null) {
            $this->sort = null;

            return $this;
        }

        $declared = $this->field($field);

        if (! in_array($declared->type, ['datetime', 'number'], true)) {
            throw new InvalidArgumentException("{$this->collection} sorts only by date and number fields, and {$field} is a {$declared->type}.");
        }

        $this->sort = $declared;

        return $this;
    }

    public function after(?string $cursor): static
    {
        $this->cursor = $cursor === '' ? null : $cursor;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    /**
     * @return array{records: Collection<int, Record>, cursor: string|null}
     */
    public function get(): array
    {
        // A value that cannot be stored cannot match.
        if (array_filter($this->filters, fn (array $filter) => $filter['hash'] === null) !== []) {
            return ['records' => new Collection(), 'cursor' => null];
        }

        $rows = $this->rows()->limit($this->limit + 1)->get();
        $page = $rows->take($this->limit);
        $ids = $page->pluck('record_id')->map(fn ($id) => (int) $id)->all();
        $found = Record::query()->whereIn('id', $ids)->get()->keyBy('id');

        return [
            'records' => collect($ids)->map(fn (int $id) => $found[$id] ?? null)->filter()->values(),
            'cursor' => $rows->count() > $this->limit ? self::encode((string) $page->last()->sort_value, (int) $page->last()->record_id) : null,
        ];
    }

    /**
     * The ids and sort values of the matching rows, in order: driven by the
     * filter with the fewest matches, or by the sort's index when every
     * filter is common.
     */
    protected function rows(): Builder
    {
        $filters = $this->bySelectivity($this->filters);
        $sortColumn = $this->sort === null ? 'sort_at' : ($this->sort->type === 'number' ? 'value_num' : 'value_time');

        if ($this->sort === null) {
            // The rarest filter's rows carry sort_at, so they are the page.
            $drive = array_shift($filters);
            $query = $this->values->visibleAs('d')
                ->where('d.key_id', $drive['key'])->where('d.value_hash', $drive['hash'])
                ->select('d.record_id', 'd.sort_at as sort_value');
            $order = 'd.sort_at';
        } elseif ($filters !== [] && $this->matches($filters[0]) <= static::$sortFirstAbove) {
            // A rare filter: read its matches and sort them by the sort row.
            $drive = array_shift($filters);
            $query = $this->values->visibleAs('d')
                ->join(RecordValues::VALUES.' as s', fn ($join) => $join->on('s.record_id', '=', 'd.record_id')->where('s.key_id', $this->values->keyId($this->sort)))
                ->where('d.key_id', $drive['key'])->where('d.value_hash', $drive['hash'])
                ->whereNotNull("s.{$sortColumn}")
                ->select('d.record_id', "s.{$sortColumn} as sort_value");
            $order = "s.{$sortColumn}";
        } else {
            // No filter, or only common ones: walk the sort's index.
            $query = $this->values->visibleAs('d')
                ->where('d.key_id', $this->values->keyId($this->sort))
                ->whereNotNull("d.{$sortColumn}")
                ->select('d.record_id', "d.{$sortColumn} as sort_value");
            $order = "d.{$sortColumn}";
        }

        foreach ($filters as $filter) {
            $query->whereExists(fn (Builder $exists) => $exists->selectRaw('1')->from(RecordValues::VALUES.' as f')
                ->whereColumn('f.record_id', 'd.record_id')
                ->where('f.key_id', $filter['key'])->where('f.value_hash', $filter['hash']));
        }

        if ($this->did !== null) {
            // By the records table's (did, collection) index.
            $query->whereIn('d.record_id', fn (Builder $records) => $records->select('id')->from('atp_records')
                ->where('did', $this->did)->where('collection', $this->collection));
        }

        if ($this->cursor !== null) {
            [$value, $id] = self::decode($this->cursor);
            $operator = $this->direction === 'desc' ? '<' : '>';

            // Redundant with the OR below, but an index can seek to it.
            $query->where($order, $operator.'=', $value);
            $query->where(fn (Builder $query) => $query->where($order, $operator, $value)
                ->orWhere(fn (Builder $query) => $query->where($order, $value)->where('d.record_id', $operator, $id)));
        }

        return $query->orderBy($order, $this->direction)->orderBy('d.record_id', $this->direction);
    }

    /**
     * Filters with the fewest matches first, by the kept counts.
     *
     * @param  array<int, array{key: int, hash: int|null, field: Field}>  $filters
     * @return array<int, array{key: int, hash: int|null, field: Field}>
     */
    protected function bySelectivity(array $filters): array
    {
        if (count($filters) < 2) {
            return $filters;
        }

        $totals = $this->values->totals(array_column($filters, 'key'), array_column($filters, 'hash'));
        usort($filters, fn (array $a, array $b) => ($totals["{$a['key']}#{$a['hash']}"] ?? 0) <=> ($totals["{$b['key']}#{$b['hash']}"] ?? 0));

        return $filters;
    }

    /**
     * @param  array{key: int, hash: int|null, field: Field}  $filter
     */
    protected function matches(array $filter): int
    {
        return $this->values->totals([$filter['key']], [$filter['hash']])["{$filter['key']}#{$filter['hash']}"] ?? 0;
    }

    protected function field(string $name): Field
    {
        return $this->values->field($this->collection, $name)
            ?? throw new InvalidArgumentException("{$name} is not an indexed field of {$this->collection}. Declare it in atp-appview.fields.");
    }

    protected static function encode(string $value, int $id): string
    {
        return rtrim(strtr(base64_encode($value.'|'.$id), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded);

        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            throw new InvalidArgumentException('Malformed cursor.');
        }

        return [$parts[0], (int) $parts[1]];
    }
}
