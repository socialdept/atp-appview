<?php

namespace SocialDept\AtpAppview\Standing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Refreshes a batch of subjects' standing, queued so reads never wait on
 * Constellation, PDSes or verification proofs.
 */
class RefreshStanding implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<int, string>  $subjects
     */
    public function __construct(
        public readonly array $subjects,
    ) {
    }

    public function handle(Standing $standing): void
    {
        $standing->refresh($this->subjects);
    }
}
