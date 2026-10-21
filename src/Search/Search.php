<?php

namespace SocialDept\AtpAppview\Search;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Features\Search as SearchFeature;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\Field;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpAppview\Search\Contracts\SearchText;
use SocialDept\AtpAppview\Views\UriViews;

/**
 * Search over the index: records of the collections atp-appview.search
 * names, matched by the words of their sources, and accounts the index
 * holds, matched by handle, display name and description. Results are
 * views and profiles, filtered for the viewer.
 */
class Search
{
    public const MAX_LIMIT = 100;

    public const TOP = 'top';

    public const LATEST = 'latest';

    /**
     * How many recent matches Top ranks.
     */
    public const TOP_CANDIDATES = 1000;

    /**
     * @return array<string, array<string, mixed>> By collection
     */
    public static function collections(): array
    {
        return (array) config('atp-appview.search.collections', []);
    }

    /**
     * A collection's sources: name => paths or SearchText classes.
     *
     * @return array<string, array<int, string>>
     */
    public static function sources(string $collection): array
    {
        return array_map(fn ($items) => array_values((array) $items), (array) (self::collections()[$collection]['sources'] ?? []));
    }

    /**
     * The name of the word field kept for one item of a source.
     */
    public static function fieldName(string $source, int $index): string
    {
        return "search.{$source}.{$index}";
    }

    /**
     * Every field the feature keeps: the words of each source, and the
     * references Top counts.
     *
     * @return array<int, array{0: string, 1: string, 2: string|array<string, mixed>}> [collection, name, declared]
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::collections() as $collection => $config) {
            foreach (self::sources($collection) as $source => $items) {
                foreach ($items as $i => $item) {
                    $fields[] = [$collection, self::fieldName($source, $i), is_subclass_of($item, SearchText::class)
                        ? ['type' => 'string[]', 'path' => '$text', 'words' => true, 'text' => $item]
                        : ['type' => 'string[]', 'path' => $item, 'words' => true]];
                }
            }

            foreach ((array) ($config['top']['counts'] ?? []) as $counted => $paths) {
                foreach (array_keys((array) $paths) as $path) {
                    $fields[] = [(string) $counted, (string) $path, 'uri'];
                }
            }
        }

        return $fields;
    }

    /**
     * Records matching a query, as views, a page at a time.
     *
     * @return array{records: array<int, array<string, mixed>>, cursor?: string, hitsTotal?: int}
     *
     * @throws SearchException for a query the index cannot answer
     */
    public function records(SearchQuery $query): array
    {
        SearchFeature::ensureOn();

        $limit = max(1, min(self::MAX_LIMIT, $query->limit));
        $base = $this->matching($query);

        if ($base === null) {
            return ['records' => [], 'hitsTotal' => 0];
        }

        if ($query->sort === self::TOP) {
            [$uris, $next] = $this->top($base, $query, $limit);
        } else {
            $page = (clone $base)->orderByDesc('sort_at')->orderByDesc('uri')
                ->cursorPaginate($limit, ['uri', 'sort_at'], 'cursor', $query->cursor);
            $uris = $page->getCollection()->pluck('uri')->all();
            $next = $page->nextCursor()?->encode();
        }

        $views = app(UriViews::class)->views($uris, $query->viewer);

        return array_filter([
            'records' => array_values(array_filter(array_map(fn (string $uri) => $views[$uri] ?? null, $uris))),
            'cursor' => $next,
            'hitsTotal' => (clone $base)->count(),
        ], fn ($value) => $value !== null);
    }

    /**
     * Accounts the index holds matching a query, as profiles. Typeahead
     * matches the start of a handle or a display name word, for completing
     * while typing. Otherwise every word must appear in the handle, display
     * name or description. Accounts the viewer follows come first, and
     * those blocked either way are left out.
     *
     * @return array{actors: array<int, array<string, mixed>>, cursor?: string}
     */
    public function accounts(string $q, ?string $viewer = null, int $limit = 25, ?string $cursor = null, bool $typeahead = false): array
    {
        SearchFeature::ensureOn();

        $term = ltrim(mb_strtolower(trim($q)), '@');
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        if ($term === '') {
            return ['actors' => []];
        }

        $query = $this->connection()->table('atp_identities as i')
            ->whereNotNull('i.handle')
            ->where('i.active', true)
            ->select('i.did');
        [$name, $description] = $this->profileColumns($query);

        if ($typeahead) {
            $like = self::escapeLike($term);
            $query->where(fn (Builder $match) => $match
                ->where('i.handle', 'like', "{$like}%")
                ->orWhereRaw("lower({$name}) like ?", ["{$like}%"])
                ->orWhereRaw("lower({$name}) like ?", ["% {$like}%"]));
        } else {
            $words = Field::words($term);

            if ($words === []) {
                return ['actors' => []];
            }

            foreach ($words as $word) {
                $like = '%'.self::escapeLike($word).'%';
                $query->where(fn (Builder $match) => $match
                    ->where('i.handle', 'like', $like)
                    ->orWhereRaw("lower({$name}) like ?", [$like])
                    ->orWhereRaw("lower({$description}) like ?", [$like]));
            }
        }

        $following = [];

        if ($viewer !== null && Graph::on()) {
            $graph = app(Graph::class);

            if (($blocked = array_values(array_unique([...$graph->blocking($viewer), ...$graph->blockedBy($viewer)]))) !== []) {
                $query->whereNotIn('i.did', $blocked);
            }

            $following = $graph->following($viewer);
        }

        if ($typeahead) {
            $query->orderByRaw('case when i.handle = ? then 0 else 1 end', [$term]);
        }

        if ($following !== []) {
            $query->orderByRaw('case when i.did in ('.implode(',', array_fill(0, count($following), '?')).') then 0 else 1 end', $following);
        }

        $offset = $typeahead ? 0 : max(0, (int) $cursor);
        $dids = $query->orderByRaw('length(i.handle)')->orderBy('i.handle')
            ->offset($offset)->limit($limit + 1)->pluck('did')->all();
        $more = count($dids) > $limit;
        $dids = array_slice($dids, 0, $limit);

        $profiles = $typeahead ? app(Profiles::class)->basic($dids) : app(Profiles::class)->for($dids);
        $relationships = $viewer !== null && Graph::on() ? app(Graph::class)->relationships($viewer, $dids) : [];

        $actors = array_values(array_filter(array_map(
            fn (string $did) => isset($profiles[$did]) ? [...$profiles[$did], ...(isset($relationships[$did]) ? ['viewer' => $relationships[$did]] : [])] : null,
            $dids,
        )));

        return array_filter([
            'actors' => $actors,
            'cursor' => ! $typeahead && $more ? (string) ($offset + $limit) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The records a query matches, before sorting, or null for a query
     * that can match nothing, such as an author the index does not know.
     *
     * @throws SearchException
     */
    protected function matching(SearchQuery $query): ?\Illuminate\Database\Eloquent\Builder
    {
        $collections = $query->collection === null ? array_keys(self::collections()) : [$query->collection];

        if ($query->collection !== null && ! isset(self::collections()[$query->collection])) {
            throw new SearchException("{$query->collection} is not searchable.");
        }

        $words = Field::words($query->q);
        $tags = array_values(array_unique(array_merge(...array_map(fn (string $tag) => Field::words($tag), [...$query->tags, '']))));

        if ($words === [] && $tags === []) {
            throw new SearchException('A search needs at least one word, number, emoji or tag.');
        }

        $keys = $this->keys($collections);
        $tagKeys = array_keys(array_filter($keys, fn (string $source) => $source === 'tags'));

        if ($tags !== [] && $tagKeys === []) {
            throw new SearchException('Nothing searched here has tags.');
        }

        $records = Record::query()->whereIn('collection', $collections)->discoverable()->visibleTo($query->viewer);
        $id = $records->getModel()->qualifyColumn('id');

        foreach ([[$words, array_keys($keys)], [$tags, $tagKeys]] as [$terms, $termKeys]) {
            foreach ($terms as $term) {
                $records->whereExists(fn ($exists) => $exists->selectRaw('1')->from(RecordValues::VALUES.' as w')
                    ->whereColumn('w.record_id', $id)
                    ->whereIn('w.key_id', $termKeys === [] ? [0] : $termKeys)
                    ->where('w.value_hash', ValueHash::of($term)));
            }
        }

        if ($query->author !== null) {
            $author = str_starts_with($query->author, 'did:')
                ? $query->author
                : $this->connection()->table('atp_identities')->where('handle', mb_strtolower(ltrim($query->author, '@')))->value('did');

            if ($author === null) {
                return null;
            }

            $records->where('did', $author);
        }

        foreach (['since' => '>=', 'until' => '<'] as $bound => $operator) {
            if ($query->{$bound} !== null) {
                try {
                    $records->where('sort_at', $operator, Carbon::parse($query->{$bound})->utc()->format('Y-m-d H:i:s.u'));
                } catch (\Throwable) {
                    throw new SearchException("{$bound} is not a date.");
                }
            }
        }

        return $records;
    }

    /**
     * A page of Top: the recent matches, ranked by their collections' signals
     * and decayed by age.
     *
     * @return array{0: array<int, string>, 1: string|null} The URIs, and the next cursor
     */
    protected function top(\Illuminate\Database\Eloquent\Builder $base, SearchQuery $query, int $limit): array
    {
        $window = max(1, ...array_map(fn (array $config) => (int) ($config['top']['window_days'] ?? 7), array_values(self::collections()) ?: [[]]));
        $candidates = (clone $base)->where('sort_at', '>=', Carbon::now('UTC')->subDays($window)->format('Y-m-d H:i:s.u'))
            ->orderByDesc('sort_at')->orderByDesc('uri')
            ->limit(self::TOP_CANDIDATES)
            ->get(['id', 'uri', 'did', 'collection', 'sort_at']);

        if ($candidates->isEmpty()) {
            return [[], null];
        }

        $following = $query->viewer !== null && Graph::on() ? array_flip(app(Graph::class)->following($query->viewer)) : [];
        $matched = $this->matchedSources($candidates->pluck('id')->all(), $query);
        $counts = $this->counts($candidates);
        $now = Carbon::now('UTC');

        $scored = $candidates->map(function (Record $record) use ($following, $matched, $counts, $now) {
            $top = (array) (self::collections()[$record->collection]['top'] ?? []);
            $signals = 1 + count($matched[$record->id] ?? []) + ($counts[$record->uri] ?? 0);

            if (isset($following[$record->did])) {
                $signals += (float) ($top['following'] ?? 5);
            }

            $hours = max(0, $now->diffInMinutes($record->sort_at ?? $now, true) / 60);

            return ['uri' => $record->uri, 'score' => $signals / (($hours + 2) ** 1.5), 'at' => $record->sort_at?->getTimestamp() ?? 0];
        })->sort(fn (array $a, array $b) => [$b['score'], $b['at'], $b['uri']] <=> [$a['score'], $a['at'], $a['uri']])->values();

        $offset = max(0, (int) $query->cursor);
        $page = $scored->slice($offset, $limit)->pluck('uri')->all();

        return [$page, $scored->count() > $offset + $limit ? (string) ($offset + $limit) : null];
    }

    /**
     * Which sources each record matched a query word in.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, true>> Record id => sources
     */
    protected function matchedSources(array $ids, SearchQuery $query): array
    {
        $keys = $this->keys($query->collection === null ? array_keys(self::collections()) : [$query->collection]);
        $hashes = array_map(fn (string $word) => ValueHash::of($word), Field::words($query->q));

        if ($keys === [] || $hashes === []) {
            return [];
        }

        $matched = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $this->connection()->table(RecordValues::VALUES)
                ->whereIn('record_id', $chunk)->whereIn('key_id', array_keys($keys))->whereIn('value_hash', $hashes)
                ->get(['record_id', 'key_id'])
                ->each(function ($row) use (&$matched, $keys) {
                    $matched[(int) $row->record_id][$keys[(int) $row->key_id]] = true;
                });
        }

        return $matched;
    }

    /**
     * The weighted counts of records pointing at each candidate, from the
     * reference fields its collection's Top names.
     *
     * @return array<string, float> URI => weighted count
     */
    protected function counts(\Illuminate\Support\Collection $candidates): array
    {
        $values = app(RecordValues::class);
        $weighted = [];

        foreach ($candidates->groupBy('collection') as $collection => $records) {
            $hashes = $records->mapWithKeys(fn (Record $record) => [$record->uri => ValueHash::of($record->uri)])->all();

            foreach ((array) (self::collections()[$collection]['top']['counts'] ?? []) as $counted => $paths) {
                foreach ((array) $paths as $path => $weight) {
                    if (($field = $values->field((string) $counted, (string) $path)) === null) {
                        continue;
                    }

                    $key = $values->keyId($field);
                    $totals = $values->totals([$key], array_values($hashes));

                    foreach ($hashes as $uri => $hash) {
                        $weighted[$uri] = ($weighted[$uri] ?? 0) + (float) $weight * ($totals["{$key}#{$hash}"] ?? 0);
                    }
                }
            }
        }

        return $weighted;
    }

    /**
     * The index keys of these collections' search fields, by key id, each
     * with its source's name.
     *
     * @param  array<int, string>  $collections
     * @return array<int, string>
     */
    protected function keys(array $collections): array
    {
        $values = app(RecordValues::class);
        $keys = [];

        foreach ($collections as $collection) {
            foreach (self::sources($collection) as $source => $items) {
                foreach (array_keys($items) as $i) {
                    if (($field = $values->field($collection, self::fieldName($source, $i))) !== null) {
                        $keys[$values->keyId($field)] = $source;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Join the first profile source, and the SQL for its display name and
     * description.
     *
     * @return array{0: string, 1: string}
     */
    protected function profileColumns(Builder $query): array
    {
        $source = app(Profiles::class)->sources()[0] ?? null;

        if ($source === null) {
            return ["''", "''"];
        }

        $query->leftJoin('atp_records as p', fn ($join) => $join->on('p.did', '=', 'i.did')
            ->where('p.collection', $source->collection)
            ->where('p.rkey', $source->rkey)
            ->whereNull('p.deleted_at'));

        $grammar = $query->getGrammar();

        return ["coalesce({$grammar->wrap('p.record->displayName')}, '')", "coalesce({$grammar->wrap('p.record->description')}, '')"];
    }

    protected static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
