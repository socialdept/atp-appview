<?php

namespace SocialDept\AtpAppview\Features;

/**
 * Labels and labelers, Tack, policies, self-labels and account actions.
 *
 * For now this only takes the options given to its Features helper. What
 * the feature adds is still registered by the package itself, and moves
 * here in a later release, after which turning it off takes effect.
 */
class Moderation implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview->config('moderation');
    }
}
