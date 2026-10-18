<?php

namespace SocialDept\AtpAppview\Lexicons;

use SocialDept\AtpSchema\Authoring\Issue;

/**
 * What syncing one followed lexicon did.
 */
final class FollowResult
{
    public const ADDED = 'added';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const REFUSED = 'refused';

    public const NOT_PUBLISHED = 'not published';

    /**
     * @param  array<int, Issue>  $issues  Breaking changes in a refused version
     */
    public function __construct(
        public readonly string $nsid,
        public readonly string $outcome,
        public readonly ?int $revision = null,
        public readonly array $issues = [],
    ) {
    }
}
