<?php

namespace SocialDept\AtpAppview\Views\Contracts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Record;

/**
 * A part whose instances in one view can share a query, such as every count
 * a view shows. The Hydrator hands them over together.
 */
interface ResolvesTogether
{
    /**
     * @param  array<int, static>  $parts
     * @param  Collection<int, Record>  $records
     * @return array<int, array<string, mixed>> Each part's result, keyed by spl_object_id()
     */
    public static function resolveTogether(array $parts, Collection $records, ?string $viewer): array;
}
