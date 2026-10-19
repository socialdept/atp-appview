<?php

namespace SocialDept\AtpAppview\Ingest\Pipes;

use Closure;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Batch;

/**
 * Drops records the blocklist covers. Identity events always pass, so
 * handles stay current for blocked repos.
 */
class DropBlocked
{
    public function __construct(protected Blocklist $blocklist)
    {
    }

    public function handle(Batch $batch, Closure $next): mixed
    {
        return $next($this->blocklist->filter($batch));
    }
}
