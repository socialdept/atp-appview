<?php

namespace SocialDept\AtpAppview\Ingest\Pipes;

use Closure;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Moderation\AccountActions;

/**
 * Drops records the blocklist covers, and records of banned accounts, so
 * nothing new is indexed for them. Identity events always pass, so handles
 * stay current for blocked repos.
 */
class DropBlocked
{
    public function __construct(protected Blocklist $blocklist)
    {
    }

    public function handle(Batch $batch, Closure $next): mixed
    {
        $batch = $this->blocklist->filter($batch);

        // Outside an application, in unit tests, there is no database.
        if (! app()->bound('db')) {
            return $next($batch);
        }

        $banned = array_flip(app(AccountActions::class)->bannedAmong(array_map(fn (RecordEvent $event) => $event->did, $batch->records())));

        if ($banned === []) {
            return $next($batch);
        }

        return $next(new Batch(array_values(array_filter(
            $batch->events,
            fn ($event) => ! ($event instanceof RecordEvent && isset($banned[$event->did])),
        ))));
    }
}
