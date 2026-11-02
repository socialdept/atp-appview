<?php

namespace SocialDept\AtpAppview\Claims;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks records whose claims send requests to one host, queued so reads
 * never wait on it. Only one runs per host at a time.
 */
class RefreshClaims implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 3600;

    /**
     * @param  array<int, string>  $uris
     */
    public function __construct(
        public readonly string $host,
        public readonly array $uris,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->host;
    }

    public function handle(Claims $claims): void
    {
        $claims->refresh($this->uris);
    }
}
