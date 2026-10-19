<?php

namespace SocialDept\AtpAppview\Ingest;

use Closure;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\Pipes\DropBlocked;
use Throwable;

/**
 * Collects delivered events into batches and acknowledges them only after
 * every handler has committed the batch.
 *
 * Tap keeps an outbox of unacknowledged events and stops sending once it is
 * full, so acknowledging late is what keeps a backfill from outrunning the
 * database.
 *
 * A batch that keeps failing is split until the events failing on their own
 * are found. They are parked as failed deliveries and the rest go through, so one
 * bad record cannot hold up everything behind it. When every event fails on
 * its own, the cause is more likely the database or a service than the
 * records, so the batch is left for Tap to redeliver, and only an event that
 * has failed in several deliveries is parked.
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
     * @var array<int, RecordEvent|IdentityEvent|LabelEvent|LabelerEvent>
     */
    protected array $events = [];

    /**
     * @param  array<int, BatchHandler>  $handlers
     * @param  Closure(int): void  $ack  Sends one acknowledgement to Tap
     * @param  Closure(int): void|null  $sleep  Waits the given milliseconds between attempts
     * @param  Blocklist|null  $blocklist  Drops blocked records before the handlers see them
     * @param  IngestStats|null  $stats  Counts what Tap delivered, blocked or not
     * @param  FailedDeliveries|null  $failedDeliveries  Parks events that keep failing. Without it, a failing batch is never acknowledged
     * @param  array<int, class-string|object>|null  $pipes  Null for atp-appview.ingest.pipes
     */
    public function __construct(
        protected array $handlers,
        protected Closure $ack,
        protected int $batchSize = 200,
        protected int $maxAttempts = 3,
        protected ?Closure $sleep = null,
        protected ?Blocklist $blocklist = null,
        protected ?IngestStats $stats = null,
        protected ?FailedDeliveries $failedDeliveries = null,
        ?array $pipes = null,
    ) {
        // The blocklist pipe uses the Blocklist given, and is left out without one.
        $pipes = array_values(array_filter(array_map(
            fn ($pipe) => $pipe === DropBlocked::class ? ($blocklist === null ? null : new DropBlocked($blocklist)) : $pipe,
            // Outside an application there is no config: only the blocklist.
            $pipes ?? (app()->bound('config') ? (array) config('atp-appview.ingest.pipes', []) : [DropBlocked::class]),
        )));

        $this->pipeline = new Pipeline(app(), $handlers, $pipes);
    }

    protected Pipeline $pipeline;

    /**
     * Add one delivery. Null stands for a message that needs acknowledging but
     * carries nothing to handle, such as an unknown type or one that failed
     * to parse.
     *
     * @return bool Whether the batch is now full and should be flushed
     */
    public function push(int $id, RecordEvent|IdentityEvent|LabelEvent|LabelerEvent|null $event): bool
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
                $this->pipeline->run($batch);

                return;
            } catch (Throwable $e) {
                if ($attempt >= $this->maxAttempts) {
                    $this->parkFailed($batch, new BatchFailed($attempt, $e));

                    return;
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

    /**
     * After the last attempt: park the events that fail on their own and let
     * the rest through, or give the batch back to Tap.
     *
     * @throws BatchFailed when it goes back to Tap
     */
    protected function parkFailed(Batch $batch, BatchFailed $failure): void
    {
        if ($this->failedDeliveries === null) {
            throw $failure;
        }

        $failed = $this->isolate($batch->events);

        if (count($failed) < $batch->count()) {
            foreach ($failed as [$event, $error]) {
                $this->failedDeliveries->park($event, $error);
            }

            Log::error('[atp-appview] Parked events that kept failing as failed deliveries', ['parked' => count($failed), 'events' => $batch->count()]);

            return;
        }

        // Everything failed on its own: more likely the database or a service.
        $due = $this->failedDeliveries->failedDelivery($batch->events);

        if (count($due) < $batch->count()) {
            throw $failure;
        }

        foreach ($failed as [$event, $error]) {
            $this->failedDeliveries->park($event, $error);
        }

        Log::error('[atp-appview] Parked a batch that failed in every delivery as failed deliveries', ['parked' => count($failed)]);
    }

    /**
     * The events that fail on their own, with their errors, found by trying
     * halves in order until single events are left: about two attempts per
     * failing event for each halving.
     *
     * @param  array<int, RecordEvent|IdentityEvent|LabelEvent|LabelerEvent>  $events
     * @return array<int, array{0: RecordEvent|IdentityEvent|LabelEvent|LabelerEvent, 1: Throwable}>
     */
    protected function isolate(array $events): array
    {
        if (count($events) === 1) {
            try {
                $this->pipeline->run(new Batch($events));

                return [];
            } catch (Throwable $e) {
                return [[$events[0], $e]];
            }
        }

        $failed = [];

        foreach (array_chunk($events, (int) ceil(count($events) / 2)) as $half) {
            try {
                $this->pipeline->run(new Batch($half));
            } catch (Throwable) {
                array_push($failed, ...$this->isolate($half));
            }
        }

        return $failed;
    }

    protected function wait(int $milliseconds): void
    {
        $this->sleep !== null ? ($this->sleep)($milliseconds) : usleep($milliseconds * 1000);
    }
}
