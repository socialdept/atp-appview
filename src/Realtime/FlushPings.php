<?php

namespace SocialDept\AtpAppview\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends what a channel gathered over its window as one ping: the latest
 * change, and how many there were.
 */
class FlushPings implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $channel,
    ) {
    }

    public function handle(Realtime $realtime): void
    {
        $realtime->flush($this->channel);
    }
}
