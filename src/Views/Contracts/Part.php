<?php

namespace SocialDept\AtpAppview\Views\Contracts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Record;

/**
 * One piece of a view, such as the author, an embed or a count. A part
 * resolves a whole page of records at once, so a page costs the same number
 * of queries whatever its size.
 */
interface Part
{
    /**
     * @param  Collection<int, Record>  $records
     * @return array<string, mixed> The part's value for each record, by uri. Missing means null.
     */
    public function resolve(Collection $records, ?string $viewer): array;
}
