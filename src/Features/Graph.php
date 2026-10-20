<?php

namespace SocialDept\AtpAppview\Features;

use SocialDept\AtpAppview\Graph\Graph as SocialGraph;

/**
 * The social graph: follows and blocks read from the collections
 * atp-appview.graph names, and private mutes. Keeps the follow and block
 * fields in the index, leaves accounts blocked either way and muted ones
 * out of what a viewer reads, and adds atp_mutes.
 *
 * Off, nothing is filtered, no fields are kept, and SocialDept\AtpAppview\
 * Graph\Graph answers as though nobody follows, blocks or mutes anyone.
 */
class Graph implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('graph')
            ->migrations(__DIR__.'/../../database/migrations/graph')
            ->readFilter(fn (string $viewer) => app(SocialGraph::class)->hidden($viewer));

        foreach ([SocialGraph::FOLLOWS, SocialGraph::BLOCKS] as $kind) {
            if (($relation = SocialGraph::relation($kind)) !== null) {
                $appview->field($relation['collection'], $relation['path'], 'uri[]');
            }
        }
    }
}
