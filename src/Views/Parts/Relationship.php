<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Contracts\Part;

/**
 * How the viewer stands with each record's author, from the graph:
 * {following, followedBy, blocking, blockedBy, muted}. Null without a
 * viewer, and for the viewer's own records. A page is read in a few queries,
 * whatever its size.
 */
class Relationship implements Part
{
    public function resolve(Collection $records, ?string $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        $relationships = app(Graph::class)->relationships($viewer, $records->pluck('did')->all());

        return $records->mapWithKeys(fn (Record $record) => [$record->uri => $relationships[$record->did] ?? null])->all();
    }
}
