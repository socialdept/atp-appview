<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Views\Contracts\Part;
use SocialDept\AtpAppview\Views\Contracts\ReadsRefs;
use SocialDept\AtpAppview\Views\Contracts\ResolvesTogether;

/**
 * How many visible records in a collection point at each record through a
 * field, such as the likes of a post. Counts are kept as records change, so
 * every count in a view is one read by primary key, never a count.
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
        $values = app(RecordValues::class);
        $keys = array_map(fn (self $part) => $values->keyId($values->field($part->collection, $part->path)), $parts);
        $hashes = $records->mapWithKeys(fn ($record) => [$record->uri => ValueHash::of($record->uri)])->all();
        $totals = $values->totals($keys, array_values($hashes));
        $results = [];

        foreach ($parts as $i => $part) {
            // Zero rather than null for a record nothing points at.
            $results[spl_object_id($part)] = array_map(fn (int $hash) => $totals["{$keys[$i]}#{$hash}"] ?? 0, $hashes);
        }

        return $results;
    }
}
