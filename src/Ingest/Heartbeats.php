<?php

namespace SocialDept\AtpAppview\Ingest;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Whether each long-running ingest process is up, for a status page. Tap
 * answers that through its admin API, and a websocket source has no such
 * thing, so it says so itself: every INTERVAL seconds it writes when it was
 * last seen, with a little state, such as how many streams it holds open.
 *
 * A heartbeat is kept for a day, so a page can say when a stopped process
 * was last seen rather than only that it is gone.
 */
class Heartbeats
{
    public const PREFIX = 'atp-appview:heartbeat:';

    /**
     * Seconds between heartbeats.
     */
    public const INTERVAL = 10;

    /**
     * Seconds a heartbeat is kept.
     */
    public const TTL = 86400;

    /**
     * @param  (Closure(): int)|null  $clock  Unix time, for tests
     */
    public function __construct(
        protected Cache $cache,
        protected ?Closure $clock = null,
    ) {
    }

    /**
     * Say a process is up. A cache that cannot be written is reported and
     * never stops ingest.
     *
     * @param  array<string, mixed>  $state
     */
    public function beat(string $process, array $state = []): void
    {
        try {
            $this->cache->put(self::PREFIX.$process, ['at' => $this->now(), 'state' => $state], self::TTL);
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // No exception handler outside an application.
            }
        }
    }

    /**
     * When a process was last seen. Null when it never was, or not within a day.
     */
    public function seenAt(string $process): ?Carbon
    {
        $beat = $this->cache->get(self::PREFIX.$process);

        return isset($beat['at']) ? Carbon::createFromTimestamp((int) $beat['at']) : null;
    }

    /**
     * What a process said with its last heartbeat.
     *
     * @return array<string, mixed>
     */
    public function state(string $process): array
    {
        return $this->cache->get(self::PREFIX.$process)['state'] ?? [];
    }

    /**
     * Whether a process was seen within three heartbeats, so one late beat
     * does not read as down.
     */
    public function running(string $process): bool
    {
        $beat = $this->cache->get(self::PREFIX.$process);

        return isset($beat['at']) && $this->now() - (int) $beat['at'] <= self::INTERVAL * 3;
    }

    protected function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
