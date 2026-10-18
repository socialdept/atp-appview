<?php

namespace SocialDept\AtpAppview\Ingest;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use Throwable;

/**
 * What the consumer has been receiving from Tap, for telling whether a
 * backfill is running.
 *
 * Tap marks each record event: `live` false while it backfills a repo, true
 * for commits arriving in real time. It exposes no count of repos still
 * waiting, so a backfill is running exactly when backfill events are still
 * arriving, and done when none have arrived for a while.
 *
 * Events are counted as Tap delivered them, before the blocklist, so a
 * backfill of blocked repos still reads as a backfill. Counts build up in
 * memory and reach the cache at most every FLUSH_INTERVAL seconds, in
 * per-minute buckets that several consumers can share.
 */
class IngestStats
{
    public const PREFIX = 'atp-appview:ingest:';

    /**
     * Seconds between writes to the cache.
     */
    public const FLUSH_INTERVAL = 5;

    /**
     * Seconds a per-minute bucket is kept.
     */
    public const BUCKET_TTL = 3600;

    public const KINDS = ['backfill', 'live', 'identity'];

    /** @var array<string, array<int, int>> Counts not yet written, by kind and minute */
    protected array $pending = [];

    /** @var array<string, int> Latest event time not yet written, by kind */
    protected array $latest = [];

    protected int $lastFlush = 0;

    /**
     * @param  (Closure(): int)|null  $clock  Unix time, for tests
     */
    public function __construct(
        protected Cache $cache,
        protected ?Closure $clock = null,
    ) {
    }

    /**
     * Count a batch as Tap delivered it.
     */
    public function record(Batch $batch): void
    {
        $now = $this->now();
        $minute = intdiv($now, 60);

        foreach ($batch->events as $event) {
            $kind = match (true) {
                $event instanceof RecordEvent => $event->live ? 'live' : 'backfill',
                $event instanceof IdentityEvent => 'identity',
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $this->pending[$kind][$minute] = ($this->pending[$kind][$minute] ?? 0) + 1;
            $this->latest[$kind] = $now;
        }

        if ($now - $this->lastFlush >= self::FLUSH_INTERVAL) {
            $this->flush();
        }
    }

    /**
     * Write what has built up. The consumer calls this as it stops, so
     * nothing counted is lost.
     *
     * Counts are only figures for a status page, so a cache that cannot be
     * written is reported and never stops ingest. What was not written stays
     * pending for the next flush, and what was is not written twice.
     */
    public function flush(): void
    {
        $this->lastFlush = $this->now();

        try {
            foreach ($this->pending as $kind => $minutes) {
                foreach ($minutes as $minute => $count) {
                    $key = $this->bucketKey($kind, $minute);
                    $this->cache->add($key, 0, self::BUCKET_TTL);
                    $this->cache->increment($key, $count);
                    unset($this->pending[$kind][$minute]);
                }

                unset($this->pending[$kind]);
            }

            foreach ($this->latest as $kind => $time) {
                $this->cache->put(self::PREFIX."last:{$kind}", $time, self::BUCKET_TTL * 24);
                unset($this->latest[$kind]);
            }
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * Whether Tap is backfilling: a backfill event arrived within the last
     * $quietSeconds. A consumer that is stopped or cut off from Tap reads as
     * not backfilling, so check it is connected too.
     */
    public function backfilling(int $quietSeconds = 60): bool
    {
        $last = $this->cache->get(self::PREFIX.'last:backfill');

        return $last !== null && $this->now() - (int) $last < $quietSeconds;
    }

    public function lastBackfillAt(): ?Carbon
    {
        return $this->lastAt('backfill');
    }

    public function lastLiveAt(): ?Carbon
    {
        return $this->lastAt('live');
    }

    /**
     * Events a minute of each kind, averaged over the last $minutes whole
     * minutes. The current minute is still filling, so it is left out.
     *
     * @return array<string, float>
     */
    public function perMinute(int $minutes = 5): array
    {
        $current = intdiv($this->now(), 60);
        $rates = [];

        foreach (self::KINDS as $kind) {
            $keys = array_map(fn (int $minute) => $this->bucketKey($kind, $minute), range($current - $minutes, $current - 1));
            $rates[$kind] = array_sum(array_map('intval', $this->cache->many($keys))) / (float) $minutes;
        }

        return $rates;
    }

    protected function lastAt(string $kind): ?Carbon
    {
        $time = $this->cache->get(self::PREFIX."last:{$kind}");

        return $time === null ? null : Carbon::createFromTimestamp((int) $time);
    }

    protected function report(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // No exception handler outside an application.
        }
    }

    protected function bucketKey(string $kind, int $minute): string
    {
        return self::PREFIX."{$kind}:{$minute}";
    }

    protected function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
