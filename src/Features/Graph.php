<?php

namespace SocialDept\AtpAppview\Features;

/**
 * The social graph: follows, blocks and mutes.
 *
 * For now this only takes the options given to its Features helper. What
 * the feature adds is still registered by the package itself, and moves
 * here in a later release, after which turning it off takes effect.
 */
class Graph implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview->config('graph');
    }
}
