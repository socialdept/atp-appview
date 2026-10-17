<?php

namespace SocialDept\AtpAppview\Ingest;

use Closure;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use Throwable;

/**
 * Collects delivered events into batches and acknowledges them only after
 * every handler has committed the batch.
 *
 * Tap keeps an outbox of unacknowledged events and stops sending once it is
 * full, so acknowledging late is what keeps a backfill from outrunning the
 * database. A batch that keeps failing is never acknowledged, and Tap
 * redelivers it.
 */
class IngestBuffer
{
    /**
     * Delivery ids waiting for the next flush, in arrival order.
     *
     * @var array<int, int>
     */
    protected array $ids = [];

    /**
     * @var array<int, RecordEvent|IdentityEvent>
     */
    protected array $events = [];

    /**
     * @param  array<int, BatchHandler>  $handlers
     * @param  Closure(int): void  $ack  Sends one acknowledgement to Tap
     * @param  Closure(int): void|null  $sleep  Waits the given milliseconds between attempts
     * @param  Blocklist|null  $blocklist  Drops blocked records before the handlers see them
     * @param  IngestStats|null  $stats  Counts what Tap delivered, blocked or not
     */
    public function __construct(
        protected array $handlers,
        protected Closure $ack,
        protected int $batchSize = 200,
        protected int $maxAttempts = 3,
        protected ?Closure $sleep = null,
        protected ?Blocklist $blocklist = null,
        protected ?IngestStats $stats = null,
    ) {
    }

    /**
     * Add one delivery. Null stands for a message that needs acknowledging but
     * carries nothing to handle, such as an unknown type or one that failed
     * to parse.
     *
     * @return bool Whether the batch is now full and should be flushed
     */
    public function push(int $id, RecordEvent|IdentityEvent|null $event): bool
    {
        $this->ids[] = $id;

        if ($event !== null) {
            $this->events[] = $event;
        }

        return count($this->ids) >= $this->batchSize;
    }

    /**
     * Hand the buffered events to every handler, then acknowledge them.
     *
     * @return bool Whether anything was flushed
     *
     * @throws BatchFailed When the handlers still fail after the last attempt
     */
    public function flush(): bool
    {
        if ($this->ids === []) {
            return false;
        }

        $ids = $this->ids;
        $batch = new Batch($this->events);
        $this->clear();

        if (! $batch->isEmpty()) {
            $this->handleWithRetries($batch);
            $this->stats?->record($batch);
        }

        foreach ($ids as $id) {
            ($this->ack)($id);
        }

        return true;
    }

    /**
     * Drop everything buffered without acknowledging it. Tap redelivers it.
     */
    public function clear(): void
    {
        $this->ids = [];
        $this->events = [];
    }

    public function pending(): int
    {
        return count($this->ids);
    }

    protected function handleWithRetries(Batch $batch): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // Inside the retries, since loading rules or looking up a PDS
                // can fail like any other database or network call.
                $allowed = $this->blocklist?->filter($batch) ?? $batch;

                if ($allowed->isEmpty()) {
                    return;
                }

                foreach ($this->handlers as $handler) {
                    $handler->handle($allowed);
                }

                return;
            } catch (Throwable $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw new BatchFailed($attempt, $e);
                }

                Log::warning('[atp-appview] Batch failed, retrying', [
                    'attempt' => $attempt,
                    'events' => $batch->count(),
                    'error' => $e->getMessage(),
                ]);

                $this->wait(100 * (2 ** ($attempt - 1)));
            }
        }
    }

    protected function wait(int $milliseconds): void
    {
        $this->sleep !== null ? ($this->sleep)($milliseconds) : usleep($milliseconds * 1000);
    }
}
