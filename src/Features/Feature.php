<?php

namespace SocialDept\AtpAppview\Features;

/**
 * Something an AppView turns on: moderation, notifications, the social
 * graph, or a third party's search backend. Listed in atp-appview.features,
 * a feature registers what it adds through AppView, and a feature left off
 * registers nothing.
 */
interface Feature
{
    /**
     * Features that must be on too, by class name.
     *
     * @return array<int, class-string<Feature>>
     */
    public function requires(): array;

    /**
     * Add what this feature brings: migrations, config, ingest handlers,
     * fields, XRPC methods, routes, commands, read filters and recommended
     * schedules. Called while the package registers.
     */
    public function register(AppView $appview): void;
}
