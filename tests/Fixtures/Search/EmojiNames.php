<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Search;

use SocialDept\AtpAppview\Search\Contracts\SearchText;

/**
 * A status's emoji as words, for a collection whose content is not words.
 */
class EmojiNames implements SearchText
{
    public const NAMES = ['🔥' => 'fire flame hot', '🦑' => 'squid'];

    public function text(array $record, string $collection): string
    {
        return self::NAMES[$record['status'] ?? ''] ?? '';
    }
}
