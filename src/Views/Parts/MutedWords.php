<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Contracts\Part;

/**
 * The viewer's muted words each record holds, as a list, for views that
 * cover muted records rather than leave them out: a profile, or a record
 * asked for by its uri. Null without a viewer, for the viewer's own records,
 * and for records holding none. A page is read in one query.
 */
class MutedWords implements Part
{
    public function resolve(Collection $records, ?string $viewer): array
    {
        if ($viewer === null || ! Graph::on()) {
            return [];
        }

        $others = $records->filter(fn (Record $record) => $record->did !== $viewer && $record->id !== null);
        $matches = app(Graph::class)->mutedWordsIn($viewer, $others->mapWithKeys(fn (Record $record) => [(int) $record->id => $record->did])->all());

        return $others->mapWithKeys(fn (Record $record) => [$record->uri => $matches[(int) $record->id] ?? null])->all();
    }
}
