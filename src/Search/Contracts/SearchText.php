<?php

namespace SocialDept\AtpAppview\Search\Contracts;

/**
 * Searchable text derived from a record, for a collection whose content is
 * not words: an emoji's names, a code's description. Listed in a search
 * source in place of a path.
 */
interface SearchText
{
    /**
     * @param  array<string, mixed>  $record
     * @return string|array<int, string>
     */
    public function text(array $record, string $collection): string|array;
}
