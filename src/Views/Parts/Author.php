<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpAppview\Views\Contracts\Part;

/**
 * Who wrote each record, as an embedded author: DID, handle, display name and
 * avatar, from Profiles.
 */
class Author implements Part
{
    public function resolve(Collection $records, ?string $viewer): array
    {
        $authors = app(Profiles::class)->basic($records->pluck('did')->all());

        return $records->mapWithKeys(fn (Record $record) => [$record->uri => $authors[$record->did]])->all();
    }
}
