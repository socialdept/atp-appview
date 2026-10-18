<?php

namespace SocialDept\AtpAppview\Lexicons;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use Throwable;

/**
 * Syncs a followed lexicon as soon as Tap delivers a change to its
 * com.atproto.lexicon.schema record, instead of on the next scheduled sync.
 *
 * The event only says which NSID changed. The schema is still fetched
 * through the NSID's DNS, so a repo DNS does not name cannot publish a
 * version this AppView uses.
 */
class FollowedLexiconsHandler implements BatchHandler
{
    public const COLLECTION = 'com.atproto.lexicon.schema';

    public function handle(Batch $batch): void
    {
        $followed = LexiconFollower::followed();

        if ($followed === []) {
            return;
        }

        $changed = [];

        foreach ($batch->records() as $event) {
            /** @var RecordEvent $event */
            if ($event->collection === self::COLLECTION && in_array($event->rkey, $followed, true)) {
                $changed[$event->rkey] = true;
            }
        }

        foreach (array_keys($changed) as $nsid) {
            try {
                // Built only when needed: most apps follow no lexicons.
                app(LexiconFollower::class)->sync($nsid);
            } catch (Throwable $e) {
                // The scheduled sync tries again. A failure here must not hold
                // up the batch, or Tap would redeliver it for ever.
                Log::warning("[atp-appview] Could not sync {$nsid} after its author changed it", ['error' => $e->getMessage()]);
            }
        }
    }
}
