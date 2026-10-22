<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Concerns\StopsWhenAsked;
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
    use StopsWhenAsked;

    protected LoopInterface $loop;

    protected IngestBuffer $buffer;

    protected ?WebSocket $connection = null;

    protected int $reconnectAttempts = 0;

    protected bool $stopping = false;

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
        protected ?IngestStats $stats = null,
        ?FailedDeliveries $failedDeliveries = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->buffer = new IngestBuffer($handlers, fn (int $id) => $this->ack($id), $batchSize, $maxAttempts, blocklist: $blocklist, stats: $stats, failedDeliveries: $failedDeliveries);
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

        if ($this->deployedFingerprint !== null) {
            $this->loop->addPeriodicTimer($this->configCheckSeconds, fn () => $this->stopIfConfigChanged());
        }

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
        $this->stats?->flush();
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

    protected function ack(int $id): void
    {
        $this->connection?->send(json_encode(['type' => 'ack', 'id' => $id]));
    }
}
