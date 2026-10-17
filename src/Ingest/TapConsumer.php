<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\TapEvents;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\Exceptions\InvalidTapEvent;

/**
 * Consumes Tap's acknowledged websocket channel in batches.
 *
 * Events are buffered until the batch is full or `flush_ms` passes, then
 * committed by the handlers and acknowledged. While a batch is being handled
 * the event loop reads nothing, so the socket buffer fills and Tap holds
 * further events: the database sets the pace, never the backfill.
 *
 * On a failed batch or a dropped connection, everything unacknowledged is
 * discarded and the consumer reconnects. Tap redelivers it.
 *
 * Like queue:work, it can exit on a time, batch or memory limit, or when
 * appview:restart is broadcast, and leave the restart to its supervisor.
 * Stopping is lossless: anything unacknowledged is redelivered.
 */
class TapConsumer
{
    protected LoopInterface $loop;

    protected IngestBuffer $buffer;

    protected ?WebSocket $connection = null;

    protected int $reconnectAttempts = 0;

    protected bool $stopping = false;

    protected int $maxTime = 0;

    protected int $maxBatches = 0;

    protected int $memoryLimit = 0;

    protected ?Cache $restartCache = null;

    protected mixed $lastRestart = null;

    protected int $startedAt = 0;

    protected int $batchesHandled = 0;

    protected ?string $stopReason = null;

    /**
     * The cache key appview:restart writes to signal running consumers.
     */
    public const RESTART_KEY = 'atp-appview:consume:restart';

    /**
     * @param  array<int, BatchHandler>  $handlers
     */
    public function __construct(
        protected array $handlers,
        protected string $url,
        protected ?string $password = null,
        protected int $batchSize = 200,
        protected int $flushMs = 500,
        protected int $maxAttempts = 3,
        protected int $maxReconnectDelay = 60,
        ?LoopInterface $loop = null,
        ?Blocklist $blocklist = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->buffer = new IngestBuffer($handlers, fn (int $id) => $this->ack($id), $batchSize, $maxAttempts, blocklist: $blocklist);
    }

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
     * Connect and consume until stopped. Blocks.
     */
    public function run(): void
    {
        $this->begin();

        $this->loop->addPeriodicTimer($this->flushMs / 1000, function () {
            $this->flush();
            $this->stopIfLimitReached();
        });

        // Signal handling needs ext-pcntl, which some PHP builds lack.
        if (function_exists('pcntl_signal')) {
            foreach ([SIGINT, SIGTERM] as $signal) {
                $this->loop->addSignal($signal, fn () => $this->stop());
            }
        }

        $this->connect();
        $this->loop->run();
    }

    /**
     * Stop after the batch in progress. Anything still buffered stays
     * unacknowledged and is redelivered on the next run.
     */
    public function stop(?string $reason = null): void
    {
        $this->stopReason ??= $reason;
        $this->stopping = true;
        $this->buffer->clear();
        $this->connection?->close();
        $this->loop->stop();
    }

    /**
     * Handle one raw message from the channel.
     */
    public function handleMessage(string $payload): void
    {
        $message = json_decode($payload, true);

        if (! is_array($message) || ($id = TapEvents::id($message)) === null) {
            Log::warning('[atp-appview] Ignoring an unreadable Tap message');

            return;
        }

        try {
            $event = TapEvents::parse($message);
        } catch (InvalidTapEvent $e) {
            // Acknowledged anyway: an event that can never parse would
            // otherwise be redelivered forever.
            Log::warning('[atp-appview] Skipping a malformed Tap event', ['id' => $id, 'error' => $e->getMessage()]);
            $event = null;
        }

        if ($this->buffer->push($id, $event)) {
            $this->flush();
            $this->stopIfLimitReached();
        }
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
     * Why the consumer stopped, when it stopped itself.
     */
    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function buffer(): IngestBuffer
    {
        return $this->buffer;
    }

    protected function flush(): void
    {
        try {
            if ($this->buffer->flush()) {
                $this->batchesHandled++;
                $this->disableQueryLogs();
            }
        } catch (BatchFailed $e) {
            Log::error('[atp-appview] Batch failed, reconnecting so Tap redelivers it', ['error' => $e->getMessage()]);
            $this->buffer->clear();
            $this->connection?->close();
        }
    }

    protected function connect(): void
    {
        $headers = $this->password !== null && $this->password !== ''
            ? ['Authorization' => 'Basic '.base64_encode('admin:'.$this->password)]
            : [];

        (new Connector($this->loop))($this->url, [], $headers)->then(
            function (WebSocket $connection) {
                $this->connection = $connection;
                $this->reconnectAttempts = 0;

                Log::info('[atp-appview] Connected to Tap', ['url' => $this->url]);

                $connection->on('message', fn (MessageInterface $message) => $this->handleMessage((string) $message));
                $connection->on('close', fn () => $this->disconnected());
            },
            function (\Throwable $e) {
                Log::error('[atp-appview] Could not connect to Tap', ['url' => $this->url, 'error' => $e->getMessage()]);
                $this->disconnected();
            },
        );
    }

    protected function disconnected(): void
    {
        $this->connection = null;
        $this->buffer->clear();

        if ($this->stopping) {
            return;
        }

        $delay = min(2 ** $this->reconnectAttempts, $this->maxReconnectDelay);
        $this->reconnectAttempts++;

        Log::warning('[atp-appview] Tap connection closed, reconnecting', ['delay' => $delay]);

        $this->loop->addTimer($delay, fn () => $this->connect());
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

    protected function ack(int $id): void
    {
        $this->connection?->send(json_encode(['type' => 'ack', 'id' => $id]));
    }
}
