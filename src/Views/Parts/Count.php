<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Index\Refs;
use SocialDept\AtpAppview\Views\Contracts\Part;
use SocialDept\AtpAppview\Views\Contracts\ReadsRefs;
use SocialDept\AtpAppview\Views\Contracts\ResolvesTogether;

/**
 * How many visible records in a collection point at each record through a
 * field, such as the likes of a post. Every count in a view is read in one
 * grouped query, from an index of atp_record_refs alone.
 */
class Count implements Part, ReadsRefs, ResolvesTogether
{
    public function __construct(
        protected string $collection,
        protected string $path,
    ) {
    }

    public function ref(): array
    {
        return [$this->collection, $this->path];
    }

    public function resolve(Collection $records, ?string $viewer): array
    {
        return self::resolveTogether([$this], $records, $viewer)[spl_object_id($this)];
    }

    public static function resolveTogether(array $parts, Collection $records, ?string $viewer): array
    {
        $rows = app(Refs::class)->visible()
            ->whereIn('target_uri', $records->pluck('uri')->all())
            ->where(fn (Builder $query) => self::forEach($query, $parts))
            ->groupBy('source_collection', 'path', 'target_uri')
            ->select('source_collection', 'path', 'target_uri')
            ->selectRaw('count(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts["{$row->source_collection}#{$row->path}"][$row->target_uri] = (int) $row->total;
        }

        $results = [];

        foreach ($parts as $part) {
            $found = $counts["{$part->collection}#{$part->path}"] ?? [];

            // Zero rather than null for a record nothing points at.
            $results[spl_object_id($part)] = $records->mapWithKeys(fn ($record) => [$record->uri => $found[$record->uri] ?? 0])->all();
        }

        return $results;
    }

    /**
     * @param  array<int, self>  $parts
     */
    protected static function forEach(Builder $query, array $parts): void
    {
        foreach ($parts as $part) {
            $query->orWhere(fn (Builder $query) => $query->where('source_collection', $part->collection)->where('path', $part->path));
        }
    }
}
