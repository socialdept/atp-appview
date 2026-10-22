<?php

namespace SocialDept\AtpAppview\Ingest\Labels;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Concerns\StopsWhenAsked;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Cursors;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpSignals\Labels\LabelStream;
use SocialDept\AtpSupport\Crypto\SignatureVerifier;
use Throwable;

/**
 * Labels from each followed labeler's own stream, in place of Tack.
 *
 * Every labeler in atp_labelers with an endpoint gets a stream, all in one
 * process, opened and closed as labelers are followed and unfollowed. A
 * stream starts at the labeler's current label and resumes from the cursor
 * saved with the batch it ends, one cursor per labeler.
 *
 * Only labels about accounts the index knows are kept, checked before their
 * signatures, which cost milliseconds each. A kept label must come from the
 * labeler whose stream carried it and verify against its #atproto_label key,
 * looked up again once when a label fails, in case the key was rotated.
 *
 * Labels made before an account was known are not on the live stream.
 */
class LabelSource
{
    use StopsWhenAsked;

    public const RESTART_KEY = TapConsumer::RESTART_KEY;

    public const CURSOR_PREFIX = 'labels:';

    /** Seconds before a labeler's key is looked up again after a failed label. */
    protected const REKEY_SECONDS = 600;

    protected IngestBuffer $buffer;

    protected bool $stopping = false;

    /** @var array<string, LabelStream> */
    protected array $streams = [];

    /** @var array<string, string> */
    protected array $endpoints = [];

    /** @var array<string, string> */
    protected array $keys = [];

    /** @var array<string, int> */
    protected array $rekeyedAt = [];

    /** @var array<string, true> */
    protected array $known = [];

    protected SignatureVerifier $verifier;

    /**
     * @param  array<int, BatchHandler>  $handlers
     * @param  (Closure(string): LabelStream)|null  $streamFactory  Makes the stream for an endpoint
     */
    public function __construct(
        protected array $handlers,
        protected Cursors $cursors,
        protected LabelerLookup $lookup,
        protected int $batchSize = 200,
        protected int $maxAttempts = 3,
        protected int $refreshSeconds = 60,
        ?Blocklist $blocklist = null,
        protected ?IngestStats $stats = null,
        ?FailedDeliveries $failedDeliveries = null,
        protected ?Closure $streamFactory = null,
    ) {
        $this->buffer = new IngestBuffer($handlers, fn () => null, PHP_INT_MAX, $maxAttempts, blocklist: $blocklist, stats: $stats, failedDeliveries: $failedDeliveries);
        $this->verifier = new SignatureVerifier();
    }

    public static function cursor(string $labeler): string
    {
        return self::CURSOR_PREFIX.$labeler;
    }

    /**
     * Follow every labeler until stopped. Blocks.
     */
    public function run(): void
    {
        $this->begin();
        $this->refresh();

        $loop = Loop::get();
        $loop->addPeriodicTimer(max(1, $this->refreshSeconds), fn () => $this->refresh());
        $loop->addPeriodicTimer(1, fn () => $this->stopIfLimitReached());

        if ($this->deployedFingerprint !== null) {
            $loop->addPeriodicTimer($this->configCheckSeconds, fn () => $this->stopIfConfigChanged());
        }

        if (function_exists('pcntl_signal')) {
            foreach ([SIGINT, SIGTERM] as $signal) {
                $loop->addSignal($signal, fn () => $this->stop());
            }
        }

        $loop->run();
    }

    /**
     * Stop after the batch in progress. A batch not yet saved comes again on
     * the next run, from its labeler's saved cursor.
     */
    public function stop(?string $reason = null): void
    {
        $this->stopReason ??= $reason;
        $this->stopping = true;
        $this->stats?->flush();
        $this->buffer->clear();

        foreach (array_keys($this->streams) as $did) {
            $this->closeStream($did);
        }

        Loop::stop();
    }

    /**
     * Reread the accounts the index knows and the labelers it follows,
     * opening and closing streams to match.
     */
    public function refresh(): void
    {
        $this->refreshKnown();

        $following = DB::connection(config('atp-appview.index.connection'))->table('atp_labelers')
            ->whereNotNull('endpoint')->pluck('endpoint', 'did')->all();

        foreach (array_keys($this->streams) as $did) {
            if (($following[$did] ?? null) !== $this->endpoints[$did]) {
                $this->closeStream($did);
            }
        }

        foreach ($following as $did => $endpoint) {
            if (! isset($this->streams[$did])) {
                $this->openStream($did, $endpoint);
            }
        }
    }

    /**
     * The labelers with an open stream.
     *
     * @return array<int, string>
     */
    public function following(): array
    {
        return array_keys($this->streams);
    }

    /**
     * Index one batch from a labeler and save its cursor with it. Public so
     * tests can hand batches over.
     *
     * @param  array<int, array<string, mixed>>  $labels  As the labeler sent them, sig included
     *
     * @throws BatchFailed when the batch fails in every attempt, so the stream redelivers it
     */
    public function handle(string $labeler, array $labels, ?int $seq): void
    {
        $events = $this->map($labeler, $labels, (int) $seq);

        DB::connection(config('atp-appview.index.connection'))->transaction(function () use ($labeler, $events, $seq) {
            foreach ($events as $event) {
                $this->buffer->push($event->id, $event);
            }

            $this->buffer->flush();

            if ($seq !== null) {
                $this->cursors->set(self::cursor($labeler), $seq);
            }
        });

        $this->batchesHandled++;
        $this->disableQueryLogs();
    }

    /**
     * The labels worth keeping from a batch: about known accounts, from this
     * labeler, and signed by it.
     *
     * @param  array<int, array<string, mixed>>  $labels
     * @return array<int, LabelEvent>
     */
    public function map(string $labeler, array $labels, int $seq = 0): array
    {
        $events = [];

        foreach ($labels as $label) {
            $uri = $label['uri'] ?? null;

            if (! is_string($uri) || ! isset($this->known[Target::subject($uri)])) {
                continue;
            }

            if (($label['src'] ?? null) !== $labeler || ! is_string($label['val'] ?? null) || ! is_string($label['cts'] ?? null)) {
                continue;
            }

            if (! $this->verified($labeler, $label)) {
                Log::warning('[atp-appview] Dropped a label whose signature does not verify', ['labeler' => $labeler, 'uri' => $uri, 'val' => $label['val']]);

                continue;
            }

            $events[] = new LabelEvent(
                id: $seq,
                src: $labeler,
                uri: $uri,
                cid: is_string($label['cid'] ?? null) ? $label['cid'] : null,
                val: $label['val'],
                neg: (bool) ($label['neg'] ?? false),
                cts: $label['cts'],
                exp: is_string($label['exp'] ?? null) ? $label['exp'] : null,
                verified: true,
            );
        }

        return $events;
    }

    /**
     * Accounts the index knows, so labels about anyone else are skipped.
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

    /**
     * @param  array<string, mixed>  $label
     */
    protected function verified(string $labeler, array $label): bool
    {
        $sig = $label['sig'] ?? null;
        unset($label['sig']);

        if (! is_string($sig)) {
            return false;
        }

        $bytes = CBOR::encode($label);

        if (isset($this->keys[$labeler]) && $this->verifier->verify($this->keys[$labeler], $bytes, $sig)) {
            return true;
        }

        // The key may have been rotated since it was looked up.
        if ($this->now() - ($this->rekeyedAt[$labeler] ?? 0) < self::REKEY_SECONDS) {
            return false;
        }

        $this->rekeyedAt[$labeler] = $this->now();

        try {
            $this->keys[$labeler] = $this->lookup->service($labeler, fresh: true)['key'];
        } catch (Throwable $e) {
            Log::warning('[atp-appview] Could not look up a labeler\'s key', ['labeler' => $labeler, 'error' => $e->getMessage()]);

            return false;
        }

        return $this->verifier->verify($this->keys[$labeler], $bytes, $sig);
    }

    protected function openStream(string $did, string $endpoint): void
    {
        try {
            $this->keys[$did] = $this->lookup->service($did)['key'];
        } catch (Throwable $e) {
            Log::warning('[atp-appview] Not following a labeler yet, its key could not be looked up', ['labeler' => $did, 'error' => $e->getMessage()]);

            return;
        }

        $stream = $this->streamFactory !== null ? ($this->streamFactory)($endpoint) : new LabelStream($endpoint, $this->batchSize);

        $this->streams[$did] = $stream;
        $this->endpoints[$did] = $endpoint;

        $stream
            ->onBatch(fn (array $labels, ?int $seq) => $this->handle($did, $labels, $seq))
            ->onInfo(fn (string $name, ?string $message) => Log::warning('[atp-appview] Labeler advisory', ['labeler' => $did, 'name' => $name, 'message' => $message]))
            ->onError(fn (Throwable $e) => $this->failed($did, $e))
            ->open(fn () => $this->cursors->get(self::cursor($did)));
    }

    protected function failed(string $did, Throwable $e): void
    {
        Log::warning('[atp-appview] Labeler stream or batch failed', ['labeler' => $did, 'error' => $e->getMessage()]);

        // A cursor ahead of the labeler means it started its sequence over: follow it from now.
        if (str_contains($e->getMessage(), 'FutureCursor')) {
            $this->cursors->forget(self::cursor($did));
        }
    }

    protected function closeStream(string $did): void
    {
        $this->streams[$did]->close();
        unset($this->streams[$did], $this->endpoints[$did]);
    }
}
