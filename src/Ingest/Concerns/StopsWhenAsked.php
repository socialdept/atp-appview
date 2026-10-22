<?php

namespace SocialDept\AtpAppview\Ingest\Concerns;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a long-running ingest process needs to stop cleanly: limits on time,
 * batches and memory, the restart signal appview:restart broadcasts, and a
 * watch on the deployed config. The using class defines RESTART_KEY, a
 * $stopping flag and stop().
 */
trait StopsWhenAsked
{
    protected int $maxTime = 0;

    protected int $maxBatches = 0;

    protected int $memoryLimit = 0;

    protected ?Cache $restartCache = null;

    protected mixed $lastRestart = null;

    /** @var (Closure(): ?string)|null */
    protected ?Closure $deployedFingerprint = null;

    protected ?string $startedFingerprint = null;

    protected int $configCheckSeconds = 60;

    protected int $startedAt = 0;

    protected int $batchesHandled = 0;

    protected ?string $stopReason = null;

    /**
     * Exit once a limit is reached. Zero disables a limit.
     *
     * @param  int  $maxTime  Seconds to run before exiting
     * @param  int  $maxBatches  Batches to handle before exiting
     * @param  int  $memory  Megabytes of memory to allow before exiting
     */
    public function withLimits(int $maxTime = 0, int $maxBatches = 0, int $memory = 0): static
    {
        $this->maxTime = max(0, $maxTime);
        $this->maxBatches = max(0, $maxBatches);
        $this->memoryLimit = max(0, $memory);

        return $this;
    }

    /**
     * Exit when appview:restart broadcasts a restart through this cache.
     */
    public function withRestartSignal(Cache $cache): static
    {
        $this->restartCache = $cache;

        return $this;
    }

    /**
     * Exit when the deployed config is no longer the one this started with,
     * checking every few seconds, so a new handler, collection or sort field
     * takes effect without a manual appview:restart.
     *
     * @param  Closure(): ?string  $deployed  The deployed config's signature now, or null when it cannot tell
     * @param  string  $started  The signature when this process started
     */
    public function withConfigWatch(Closure $deployed, string $started, int $everySeconds = 60): static
    {
        $this->deployedFingerprint = $deployed;
        $this->startedFingerprint = $started;
        $this->configCheckSeconds = max(1, $everySeconds);

        return $this;
    }

    /**
     * Whether the deployed config differs from the one this started with.
     */
    public function configChanged(): bool
    {
        if ($this->deployedFingerprint === null) {
            return false;
        }

        $deployed = ($this->deployedFingerprint)();

        return $deployed !== null && $deployed !== $this->startedFingerprint;
    }

    /**
     * The limit that is reached, if any, as a reason to stop.
     */
    public function limitReached(): ?string
    {
        return match (true) {
            $this->maxBatches > 0 && $this->batchesHandled >= $this->maxBatches => "handled {$this->batchesHandled} batches",
            $this->memoryLimit > 0 && $this->memoryUsage() >= $this->memoryLimit => "memory reached {$this->memoryUsage()} MB",
            $this->maxTime > 0 && $this->now() - $this->startedAt >= $this->maxTime => "ran for {$this->maxTime} seconds",
            $this->restartCache !== null && $this->restartCache->get(self::RESTART_KEY) != $this->lastRestart => 'restart signal received',
            default => null,
        };
    }

    /**
     * Why the process stopped, when it stopped itself.
     */
    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * Reset the run's counters and note the restart signal as it stands now.
     */
    protected function begin(): void
    {
        $this->stopping = false;
        $this->stopReason = null;
        $this->startedAt = $this->now();
        $this->batchesHandled = 0;
        $this->lastRestart = $this->restartCache?->get(self::RESTART_KEY);
        $this->disableQueryLogs();
    }

    protected function stopIfConfigChanged(): void
    {
        if (! $this->stopping && $this->configChanged()) {
            Log::info('[atp-appview] Stopping the consumer', ['reason' => 'the deployed config changed']);
            $this->stop('the deployed config changed');
        }
    }

    protected function stopIfLimitReached(): void
    {
        if (! $this->stopping && ($reason = $this->limitReached()) !== null) {
            Log::info('[atp-appview] Stopping the consumer', ['reason' => $reason]);
            $this->stop($reason);
        }
    }

    /**
     * Keep a long-running process from accumulating every query it runs. The
     * log is off by default, but tools such as Telescope or Debugbar turn it
     * on, and handlers may open connections after startup.
     */
    protected function disableQueryLogs(): void
    {
        if (! app()->bound('db')) {
            return;
        }

        foreach (DB::getConnections() as $connection) {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    protected function memoryUsage(): int
    {
        return (int) (memory_get_usage(true) / 1024 / 1024);
    }

    protected function now(): int
    {
        return time();
    }
}
