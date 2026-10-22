<?php

namespace SocialDept\AtpAppview\Ingest\Jetstream;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Ingest\Concerns\StopsWhenAsked;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Cursors;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Jetstream\JetstreamStream;
use Throwable;

/**
 * Records, identities and account status from Jetstream v2, in place of Tap.
 *
 * One stream follows the app's collections and its companion collections,
 * with identity and account events. Jetstream cannot tell which repos the
 * app follows, so the app does: a companion record, an identity or an
 * account event counts only for a repo the index already knows. A record in
 * one of the app's own collections from a repo it does not know adds that
 * repo, and its handle is looked up in the queue.
 *
 * Batches go through the ingest buffer, as Tap's do, so retries and failed
 * deliveries work the same. The cursor is saved in the same transaction as
 * the batch it ends.
 *
 * Jetstream checks commit signatures upstream, so records are as trusted as
 * the Jetstream instance. Backfill is not part of this: it follows from the
 * moment the cursor starts.
 */
class JetstreamSource
{
    use StopsWhenAsked;

    public const RESTART_KEY = TapConsumer::RESTART_KEY;

    public const CURSOR = 'jetstream';

    protected IngestBuffer $buffer;

    protected ?JetstreamStream $stream = null;

    protected bool $stopping = false;

    /** @var array<string, true> */
    protected array $known = [];

    /** @var array<int, string> */
    protected array $collections;

    /**
     * @param  array<int, BatchHandler>  $handlers
     */
    public function __construct(
        protected array $handlers,
        protected Cursors $cursors,
        protected string $url,
        protected int $batchSize = 200,
        protected int $maxAttempts = 3,
        protected int $knownRefreshSeconds = 60,
        ?Blocklist $blocklist = null,
        protected ?IngestStats $stats = null,
        ?FailedDeliveries $failedDeliveries = null,
    ) {
        $this->buffer = new IngestBuffer($handlers, fn () => null, PHP_INT_MAX, $maxAttempts, blocklist: $blocklist, stats: $stats, failedDeliveries: $failedDeliveries);
        $this->collections = array_values(array_unique([...Collections::followed(), ...Collections::companions()]));
    }

    /**
     * The stream this source reads, made once.
     */
    public function stream(): JetstreamStream
    {
        return $this->stream ??= new JetstreamStream($this->url, $this->collections, [], ['commit', 'identity', 'account'], $this->batchSize);
    }

    /**
     * Follow Jetstream until stopped. Blocks.
     */
    public function run(): void
    {
        $this->begin();
        $this->refreshKnown();

        $loop = Loop::get();
        $loop->addPeriodicTimer(max(1, $this->knownRefreshSeconds), fn () => $this->refreshKnown());
        $loop->addPeriodicTimer(1, fn () => $this->stopIfLimitReached());

        if ($this->deployedFingerprint !== null) {
            $loop->addPeriodicTimer($this->configCheckSeconds, fn () => $this->stopIfConfigChanged());
        }

        if (function_exists('pcntl_signal')) {
            foreach ([SIGINT, SIGTERM] as $signal) {
                $loop->addSignal($signal, fn () => $this->stop());
            }
        }

        $this->stream()
            ->onBatch(fn (array $events, ?int $seq) => $this->handle($events, $seq))
            ->onInfo(fn (string $name, ?string $message) => Log::warning('[atp-appview] Jetstream advisory', ['name' => $name, 'message' => $message]))
            ->onError(fn (Throwable $e) => $this->failed($e))
            ->start(fn () => $this->cursors->get(self::CURSOR));
    }

    /**
     * Stop after the batch in progress. A batch not yet saved comes again on
     * the next run, from the saved cursor.
     */
    public function stop(?string $reason = null): void
    {
        $this->stopReason ??= $reason;
        $this->stopping = true;
        $this->stats?->flush();
        $this->buffer->clear();
        $this->stream?->stop();
    }

    /**
     * Index one batch from the stream and save the cursor with it. Public so
     * tests can hand batches over.
     *
     * @param  array<int, SignalEvent>  $events
     *
     * @throws BatchFailed when the batch fails in every attempt, so the stream redelivers it
     */
    public function handle(array $events, ?int $seq): void
    {
        $mapped = $this->map($events);
        $connection = DB::connection(config('atp-appview.index.connection'));

        $connection->transaction(function () use ($mapped, $seq) {
            foreach ($mapped as $event) {
                $this->buffer->push($event->id, $event);
            }

            $this->buffer->flush();

            if ($seq !== null) {
                $this->cursors->set(self::CURSOR, $seq);
            }
        });

        $this->batchesHandled++;
        $this->disableQueryLogs();
    }

    /**
     * The events the index takes from a batch, with repos it now knows.
     *
     * @param  array<int, SignalEvent>  $events
     * @return array<int, RecordEvent|IdentityEvent>
     */
    public function map(array $events): array
    {
        $dids = array_values(array_unique(array_map(fn (SignalEvent $event) => $event->did, $events)));
        $current = DB::connection(config('atp-appview.index.connection'))->table('atp_identities')
            ->whereIn('did', $dids)->get(['did', 'handle', 'active', 'status'])->keyBy('did');
        $added = [];
        $mapped = [];

        foreach ($events as $event) {
            $known = isset($this->known[$event->did]) || $current->has($event->did);

            if ($event->isCommit() && $event->commit !== null) {
                $own = ! Collections::isCompanion($event->commit->collection);

                // A companion record counts only for a repo the app follows.
                if (! $known && ! $own) {
                    continue;
                }

                if (! $known) {
                    $this->known[$event->did] = true;
                    $added[$event->did] = true;
                }

                $mapped[] = new RecordEvent(
                    id: (int) $event->seq,
                    did: $event->did,
                    collection: $event->commit->collection,
                    rkey: $event->commit->rkey,
                    action: match (true) {
                        $event->commit->isDelete() => RecordAction::Delete,
                        $event->commit->isUpdate() => RecordAction::Update,
                        default => RecordAction::Create,
                    },
                    cid: $event->commit->cid,
                    rev: $event->commit->rev,
                    record: $event->commit->record === null ? null : (array) $event->commit->record,
                    live: true,
                );

                continue;
            }

            // Identity and account events come for every repo on the network.
            if (! $known) {
                continue;
            }

            $row = $current->get($event->did);

            if ($event->isIdentity()) {
                $mapped[] = new IdentityEvent((int) $event->seq, $event->did, $event->identity?->handle, (bool) ($row->active ?? true), $row->status ?? null);
            } elseif ($event->isAccount() && $event->account !== null) {
                $mapped[] = new IdentityEvent((int) $event->seq, $event->did, $row->handle ?? null, $event->account->active, $event->account->active ? null : $event->account->status);
            }
        }

        if ($added !== []) {
            DB::afterCommit(fn () => ResolveIdentity::dispatch(array_keys($added)));
        }

        return $mapped;
    }

    /**
     * Repos the index knows, reread now and then so repos added elsewhere,
     * such as by a backfill, count.
     */
    public function refreshKnown(): void
    {
        $known = [];

        DB::connection(config('atp-appview.index.connection'))->table('atp_identities')
            ->orderBy('did')->select('did')
            ->chunk(5000, function ($rows) use (&$known) {
                foreach ($rows as $row) {
                    $known[$row->did] = true;
                }
            });

        $this->known = $known;
    }

    protected function failed(Throwable $e): void
    {
        Log::warning('[atp-appview] Jetstream connection or batch failed', ['error' => $e->getMessage()]);

        // A cursor older than Jetstream keeps cannot be resumed: catching up is a backfill.
        if (str_contains($e->getMessage(), 'CursorTooOld')) {
            Log::error('[atp-appview] The Jetstream cursor is older than the instance keeps. Run a backfill, then appview:jetstream --from-live.');
            $this->stop('the cursor is older than Jetstream keeps');
        }
    }
}
