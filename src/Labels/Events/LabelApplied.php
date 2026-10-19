<?php

namespace SocialDept\AtpAppview\Labels\Events;

use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Models\Label;

/**
 * A label now applies to an account or a record, with what it does there.
 * Dispatched once the label is committed. Listen for it to do what effects
 * do not, such as telling someone.
 */
class LabelApplied
{
    public function __construct(
        public readonly Label $label,
        public readonly string $target,
        public readonly Effect $effect,
    ) {
    }
}
