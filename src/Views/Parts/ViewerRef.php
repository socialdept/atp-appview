<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Views\Contracts\Part;
use SocialDept\AtpAppview\Views\Contracts\ReadsRefs;
use SocialDept\AtpAppview\Views\Contracts\ResolvesTogether;

/**
 * The viewer's own record pointing at each record through a field, such as
 * the viewer's like of a post: its uri, or null. Every one in a view is read
 * in one query, and nothing is looked up without a viewer.
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

        $values = app(RecordValues::class);
        $keys = array_map(fn (self $part) => $values->keyId($values->field($part->collection, $part->path)), $parts);
        $uris = $records->mapWithKeys(fn ($record) => [ValueHash::of($record->uri) => $record->uri])->all();

        $rows = $values->visible()
            ->join('atp_records', 'atp_records.id', '=', RecordValues::VALUES.'.record_id')
            ->where('did_hash', ValueHash::of($viewer))
            ->whereIn('key_id', array_values(array_unique($keys)))
            ->whereIn('value_hash', array_keys($uris))
            ->orderBy('atp_records.uri')
            ->get(['key_id', 'value_hash', 'atp_records.uri as source_uri']);

        $found = [];

        foreach ($rows as $row) {
            // The first of several, in uri order, so the answer is stable.
            $found[(int) $row->key_id][$uris[(int) $row->value_hash]] ??= $row->source_uri;
        }

        foreach ($parts as $i => $part) {
            $results[spl_object_id($part)] = $found[$keys[$i]] ?? [];
        }

        return $results;
    }
}
