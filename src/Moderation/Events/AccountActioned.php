<?php

namespace SocialDept\AtpAppview\Moderation\Events;

use SocialDept\AtpAppview\Models\AccountAction;

/**
 * An account was banned, put under review or taken down, or one of those
 * was lifted. Dispatched once the change is committed.
 */
class AccountActioned
{
    public function __construct(
        public readonly AccountAction $action,
        public readonly bool $lifted,
    ) {
    }
}
