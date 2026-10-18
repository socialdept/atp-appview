<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Index\Refs;
use SocialDept\AtpAppview\Views\Contracts\Part;
use SocialDept\AtpAppview\Views\Contracts\ReadsRefs;
use SocialDept\AtpAppview\Views\Contracts\ResolvesTogether;

/**
 * The viewer's own record pointing at each record through a field, such as
 * the viewer's like of a post: its uri, or null. Every one in a view is read
 * in one query from an index alone, and nothing is looked up without a viewer.
 */
class ViewerRef implements Part, ReadsRefs, ResolvesTogether
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
        $results = array_fill_keys(array_map('spl_object_id', $parts), []);

        if ($viewer === null) {
            return $results;
        }

        $rows = app(Refs::class)->visible()
            ->where('source_did', $viewer)
            ->where(function (Builder $query) use ($parts) {
                foreach ($parts as $part) {
                    $query->orWhere(fn (Builder $query) => $query->where('source_collection', $part->collection)->where('path', $part->path));
                }
            })
            ->whereIn('target_uri', $records->pluck('uri')->all())
            ->orderBy('source_uri')
            ->get(['source_collection', 'path', 'target_uri', 'source_uri']);

        $found = [];

        foreach ($rows as $row) {
            // The first of several, in uri order, so the answer is stable.
            $found["{$row->source_collection}#{$row->path}"][$row->target_uri] ??= $row->source_uri;
        }

        foreach ($parts as $part) {
            $results[spl_object_id($part)] = $found["{$part->collection}#{$part->path}"] ?? [];
        }

        return $results;
    }
}
