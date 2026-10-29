<?php

namespace SocialDept\AtpAppview\Features;

use SocialDept\AtpAppview\Graph\Graph as SocialGraph;
use SocialDept\AtpAppview\Index\RecordTables;

/**
 * The social graph: follows and blocks read from the collections
 * atp-appview.graph names, and private mutes of accounts and of words. Keeps
 * the follow, block and muted word fields in the index, leaves accounts
 * blocked either way or muted, and records holding a muted word, out of what
 * a viewer reads, and adds atp_mutes and atp_muted_words.
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

        // The words of each muted word source, kept so a muted word is an
        // index lookup, and records holding one left out for the viewer.
        foreach (SocialGraph::mutedWordSources() as $collection => $sources) {
            foreach ($sources as $source => $paths) {
                foreach ($paths as $i => $path) {
                    $appview->field($collection, SocialGraph::mutedWordField($source, $i), ['type' => 'string[]', 'path' => $path, 'words' => true]);
                }
            }
        }

        $appview->recordFilter(fn ($query, string $viewer, string $idColumn, RecordTables $tables) => app(SocialGraph::class)->applyMutedWords($query, $viewer, $idColumn, $tables), spaces: true);
    }
}
