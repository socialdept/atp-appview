<?php

namespace SocialDept\AtpAppview\Labels\Events;

/**
 * A labeler withdrew a label, or it was removed with its labeler.
 * Dispatched once the change is committed.
 */
class LabelWithdrawn
{
    public function __construct(
        public readonly string $src,
        public readonly string $uri,
        public readonly string $val,
    ) {
    }
}
