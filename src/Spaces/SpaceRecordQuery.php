<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Features\Spaces as SpacesFeature;
use SocialDept\AtpAppview\Index\Field;
use SocialDept\AtpAppview\Index\RecordTables;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\Policies;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpAppview\Models\AccountAction;
use SocialDept\AtpAppview\Models\Casts\PreciseDateTime;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\SpaceRecord;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * A page of a followed space's records, for a viewer SpaceAccess allows.
 *
 *     SpaceRecordQuery::in($space)
 *         ->visibleTo($did)
 *         ->collection('com.example.post')
 *         ->after($cursor)
 *         ->limit(50)
 *         ->get();
 *
 * Without visibleTo(), or for a viewer SpaceAccess does not allow, it finds
 * nothing. Records come newest first by sort_at, in keyset pages that never
 * skip or repeat a record while new ones arrive. With a collection, where()
 * and orderBy() filter and sort by its indexed fields, as RecordQuery does,
 * from atp_space_record_values.
 *
 * Moderation applies as it does to public records: nothing by accounts the
 * viewer blocks, is blocked by or muted, or by inactive accounts, and with
 * the moderation feature nothing by banned accounts or that a label hides.
 * What needs review is left out unless the query is for one repo. Record
 * filters registered for spaces, such as muted words and the viewer's label
 * preferences, apply too.
 */
class SpaceRecordQuery
{
    protected ?string $collection = null;

    /** @var array<int, array{0: string, 1: mixed}> Field names and values, read once the collection is known */
    protected array $wheres = [];

    protected ?string $sort = null;

    protected string $direction = 'desc';

    protected ?string $did = null;

    protected bool $scoped = false;

    protected ?string $viewer = null;

    protected ?string $cursor = null;

    protected int $limit = 50;

    /**
     * @throws LogicException when the spaces feature is off
     */
    public function __construct(protected SpaceRef $space)
    {
        SpacesFeature::ensureOn();
    }

    /**
     * @param  SpaceRef|string  $space  A space, or its URI
     */
    public static function in(SpaceRef|string $space): static
    {
        return new static($space instanceof SpaceRef ? $space : SpaceRef::parse($space));
    }

    /**
     * Only records in one collection.
     */
    public function collection(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    /**
     * Only records in one member's repo.
     */
    public function by(string $did): static
    {
        $this->did = $did;

        return $this;
    }

    /**
     * Who is reading, a DID or null for a signed-out viewer. Records come
     * back only when SpaceAccess allows them the space.
     */
    public function visibleTo(?string $viewer): static
    {
        $this->scoped = true;
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * Only records whose field has this value. Several are all required.
     * Needs collection(), since fields are declared per collection.
     */
    public function where(string $field, mixed $value): static
    {
        $this->wheres[] = [$field, $value];

        return $this;
    }

    /**
     * Order by a date or number field, or by sort_at when null. Sorting by a
     * field leaves out records that have no value for it.
     */
    public function orderBy(?string $field = null, string $direction = 'desc'): static
    {
        $this->sort = $field;
        $this->direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

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
     * @return array{records: Collection<int, SpaceRecord>, cursor: string|null}
     */
    public function get(): array
    {
        if (! $this->scoped || ! app(SpaceAccess::class)->allows($this->space, $this->viewer)) {
            return ['records' => new Collection(), 'cursor' => null];
        }

        $filters = $this->filters();
        $sort = $this->sort === null ? null : $this->sortField();

        // A value that cannot be stored cannot match.
        if (array_filter($filters, fn (array $filter) => $filter['hash'] === null) !== []) {
            return ['records' => new Collection(), 'cursor' => null];
        }

        $rows = $this->query($filters, $sort)->limit($this->limit + 1)->get();
        $page = $rows->take($this->limit)->values();
        $last = $page->last();
        $cursor = $rows->count() > $this->limit
            ? self::encode($sort === null ? $last->sort_at->format(PreciseDateTime::FORMAT) : (string) $last->getAttribute('sort_value'), $last->uri)
            : null;

        // The sort value was only read for the cursor.
        if ($sort !== null) {
            $page->each(fn (SpaceRecord $record) => $record->setRawAttributes(Arr::except($record->getAttributes(), 'sort_value'), true));
        }

        return ['records' => $page, 'cursor' => $cursor];
    }

    /**
     * @param  array<int, array{key: int, hash: int|null}>  $filters
     * @return Builder<SpaceRecord>
     */
    protected function query(array $filters, ?Field $sort): Builder
    {
        $records = SpaceRecordIndexer::TABLE;
        $space = ValueHash::of((string) $this->space);
        $order = "{$records}.sort_at";

        $query = SpaceRecord::query()
            ->select("{$records}.*")
            ->where("{$records}.space", (string) $this->space)
            ->whereNotNull("{$records}.sort_at")
            ->when($this->collection !== null, fn (Builder $query) => $query->where("{$records}.collection", $this->collection))
            ->when($this->did !== null, fn (Builder $query) => $query->where("{$records}.did", $this->did));

        // The rarest filter's rows carry sort_at, so they drive the page.
        if (($drive = array_shift($filters)) !== null) {
            $query->join(SpaceRecordValues::TABLE.' as d', fn ($join) => $join->on('d.record_id', '=', "{$records}.id")
                ->where('d.space_hash', $space)->where('d.key_id', $drive['key'])->where('d.value_hash', $drive['hash']));
            $order = 'd.sort_at';
        }

        foreach ($filters as $filter) {
            $query->whereExists(fn ($exists) => $exists->selectRaw('1')->from(SpaceRecordValues::TABLE.' as f')
                ->whereColumn('f.record_id', "{$records}.id")
                ->where('f.key_id', $filter['key'])->where('f.value_hash', $filter['hash']));
        }

        if ($sort !== null) {
            $column = $sort->type === 'number' ? 'value_num' : 'value_time';
            $query->join(SpaceRecordValues::TABLE.' as s', fn ($join) => $join->on('s.record_id', '=', "{$records}.id")
                ->where('s.space_hash', $space)->where('s.key_id', app(RecordValues::class)->keyId($sort)))
                ->whereNotNull("s.{$column}")
                ->addSelect("s.{$column} as sort_value");
            $order = "s.{$column}";
        }

        if ($this->viewer !== null && ($hidden = app(AppView::class)->hidden($this->viewer)) !== []) {
            $query->whereNotIn("{$records}.did", $hidden);
        }

        if ($this->viewer !== null) {
            app(AppView::class)->applyRecordFilters($query, $this->viewer, "{$records}.id", RecordTables::spaces());
        }

        $this->moderate($query);

        if ($this->cursor !== null) {
            [$value, $uri] = self::decode($this->cursor);
            $operator = $this->direction === 'desc' ? '<' : '>';

            // Redundant with the OR below, but an index can seek to it.
            $query->where($order, $operator.'=', $value);
            $query->where(fn (Builder $query) => $query->where($order, $operator, $value)
                ->orWhere(fn (Builder $query) => $query->where($order, $value)->where("{$records}.uri", $operator, $uri)));
        }

        return $query->orderBy($order, $this->direction)->orderBy("{$records}.uri", $this->direction);
    }

    /**
     * The filters, rarest first in this space when there are several, so
     * the rarest drives the page.
     *
     * @return array<int, array{key: int, hash: int|null}>
     */
    protected function filters(): array
    {
        $values = app(RecordValues::class);
        $filters = [];

        foreach ($this->wheres as [$name, $value]) {
            $field = $this->field($name);
            $filters[] = ['key' => $values->keyId($field), 'hash' => $field->hashOf($value)];
        }

        if (count($filters) < 2 || in_array(null, array_column($filters, 'hash'), true)) {
            return $filters;
        }

        // One count per value, read from the space's own rows.
        $totals = SpaceRecord::query()->getConnection()->table(SpaceRecordValues::TABLE)
            ->where('space_hash', ValueHash::of((string) $this->space))
            ->whereIn('key_id', array_values(array_unique(array_column($filters, 'key'))))
            ->whereIn('value_hash', array_values(array_unique(array_column($filters, 'hash'))))
            ->groupBy('key_id', 'value_hash')
            ->selectRaw('key_id, value_hash, count(*) as n')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->key_id}#{$row->value_hash}" => (int) $row->n])
            ->all();

        usort($filters, fn (array $a, array $b) => ($totals["{$a['key']}#{$a['hash']}"] ?? 0) <=> ($totals["{$b['key']}#{$b['hash']}"] ?? 0));

        return $filters;
    }

    protected function sortField(): Field
    {
        $field = $this->field((string) $this->sort);

        if (! in_array($field->type, ['datetime', 'number'], true)) {
            throw new InvalidArgumentException("{$this->collection} sorts only by date and number fields, and {$field->name} is a {$field->type}.");
        }

        return $field;
    }

    protected function field(string $name): Field
    {
        if ($this->collection === null) {
            throw new LogicException("Filtering or sorting space records by {$name} needs collection(), since fields are declared per collection.");
        }

        return app(RecordValues::class)->field($this->collection, $name)
            ?? throw new InvalidArgumentException("{$name} is not an indexed field of {$this->collection}. Declare it in atp-appview.fields.");
    }

    /**
     * Leave out what moderation hides, and, across repos, what needs review.
     * Space records carry no moderation columns, since labels and account
     * actions are about accounts and public URIs, so it is worked out here
     * from the accounts and labels as they are now.
     *
     * @param  Builder<SpaceRecord>  $query
     */
    protected function moderate(Builder $query): void
    {
        $records = $query->getModel()->getTable();

        // Deactivated, taken down or suspended.
        $query->whereNotExists(fn ($identities) => $identities->selectRaw('1')->from('atp_identities')
            ->whereColumn('atp_identities.did', "{$records}.did")
            ->where('atp_identities.active', false));

        // Labels and account actions come with the moderation feature.
        if (! ModerationFeature::on()) {
            return;
        }

        $actions = $this->did === null ? [AccountAction::BAN, AccountAction::REVIEW] : [AccountAction::BAN];

        $query->whereNotExists(fn ($active) => $active->selectRaw('1')->from('atp_account_actions')
            ->whereColumn('atp_account_actions.did', "{$records}.did")
            ->whereIn('atp_account_actions.action', $actions)
            ->whereNull('atp_account_actions.lifted_at'));

        [$accounts, $uris] = $this->labelled();

        if ($accounts !== []) {
            $query->whereNotIn("{$records}.did", $accounts);
        }

        if ($uris !== []) {
            $query->whereNotIn("{$records}.uri", $uris);
        }
    }

    /**
     * The accounts and records labels keep out: what a label hides, and what
     * needs review unless the query is for one repo.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} Account DIDs and record URIs
     */
    protected function labelled(): array
    {
        $space = (string) $this->space;
        $held = fn (string $column) => SpaceRecord::query()->withTrashed()->select($column)->where('space', $space);

        $labels = Label::query()->live()
            ->where(fn ($query) => $query
                // Labels on members' accounts, or their profiles.
                ->where(fn ($query) => $query->whereIn('subject', $held('did')->distinct())
                    ->where(fn ($query) => $query->where('uri', 'not like', 'at://%')->orWhere('uri', 'like', 'at://%/app.bsky.actor.profile/%')))
                // Labels on this space's records.
                ->orWhereIn('uri', $held('uri')))
            ->get(['src', 'uri', 'val']);

        $policies = app(Policies::class);
        $accounts = $uris = [];

        foreach ($labels as $label) {
            $target = str_starts_with($label->uri, $space.'/') ? Target::CONTENT : Target::ACCOUNT;
            $effect = $policies->effect($label->src, $label->val, $target);

            if (! $effect->hides() && ($effect !== Effect::Review || $this->did !== null)) {
                continue;
            }

            if ($target === Target::ACCOUNT) {
                $accounts[] = Target::subject($label->uri);
            } else {
                $uris[] = $label->uri;
            }
        }

        return [array_values(array_unique($accounts)), array_values(array_unique($uris))];
    }

    protected static function encode(string $value, string $uri): string
    {
        return rtrim(strtr(base64_encode($value.'|'.$uri), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded, 2);

        if (count($parts) !== 2 || $parts[0] === '' || ! str_starts_with($parts[1], 'at://')) {
            throw new InvalidArgumentException('Malformed cursor.');
        }

        return $parts;
    }
}
