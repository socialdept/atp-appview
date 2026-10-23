<?php

namespace SocialDept\AtpAppview\Ingest\Labels;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Labels\Target;

/**
 * Fetches the labels a labeler already has on some accounts or records,
 * which its live stream will not send again.
 *
 * - Account labels, by DID and profile record, from every labeler.
 * - Record labels by at://did/* from a labeler that takes wildcards, and by
 *   each record's URI from one that does not. Whether it does is found out
 *   on the first try and kept on the labeler.
 * - Records indexed late, given as URIs, are asked about only where
 *   wildcards are not known to work.
 *
 * The newest label per URI and value decides, since queryLabels pages in no
 * order. Labels go through the ingest pipeline like streamed ones, checked
 * against the labeler's key first.
 */
class BackfillLabels implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Accounts per job: each takes two patterns. */
    public const ACCOUNTS = 50;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, string>  $dids  Accounts new to the labeler
     * @param  array<int, string>  $uris  Records of known accounts indexed late
     */
    public function __construct(
        public readonly string $labeler,
        public readonly array $dids = [],
        public readonly array $uris = [],
    ) {
    }

    public function handle(LabelQuery $query, LabelVerifier $verifier): void
    {
        $connection = DB::connection(config('atp-appview.index.connection'));
        $row = $connection->table('atp_labelers')->where('did', $this->labeler)->first(['endpoint', 'wildcards']);

        // Unfollowed, or no longer serving labels, since this was queued.
        if ($row === null || $row->endpoint === null) {
            return;
        }

        $wildcards = $row->wildcards === null ? null : (bool) $row->wildcards;
        $labels = [];
        $ask = function (array $patterns) use ($query, $row, &$labels) {
            foreach (array_chunk($patterns, LabelQuery::PATTERNS) as $chunk) {
                array_push($labels, ...$query->labels($row->endpoint, $this->labeler, $chunk));
            }
        };

        if ($this->dids !== []) {
            $ask(array_merge(...array_map(fn ($did) => [$did, "at://{$did}/app.bsky.actor.profile/self"], $this->dids)));

            if ($wildcards !== false) {
                try {
                    $ask(array_map(fn ($did) => "at://{$did}/*", $this->dids));
                    $wildcards = true;
                } catch (WildcardsOff) {
                    $wildcards = false;
                }

                $connection->table('atp_labelers')->where('did', $this->labeler)->update(['wildcards' => $wildcards]);
            }

            if ($wildcards === false) {
                $ask($connection->table('atp_records')->whereIn('did', $this->dids)->whereNull('deleted_at')->orderBy('uri')->pluck('uri')->all());
            }
        }

        if ($this->uris !== [] && $wildcards !== true) {
            $ask($this->uris);
        }

        $events = $this->events($labels, $verifier);

        if ($events !== []) {
            Pipeline::fromConfig(app())->run(new Batch($events));
        }
    }

    /**
     * The newest label per URI and value about what was asked, if it verifies.
     *
     * @param  array<int, array<string, mixed>>  $labels
     * @return array<int, LabelEvent>
     */
    protected function events(array $labels, LabelVerifier $verifier): array
    {
        $asked = array_flip([...$this->dids, ...array_map(fn ($uri) => Target::subject($uri), $this->uris)]);
        $newest = [];

        foreach ($labels as $label) {
            if (! is_string($label['uri'] ?? null) || ! is_string($label['val'] ?? null) || ! is_string($label['cts'] ?? null)) {
                continue;
            }

            if (($label['src'] ?? null) !== $this->labeler || ! isset($asked[Target::subject($label['uri'])])) {
                continue;
            }

            $key = "{$label['uri']}\0{$label['val']}";

            if (! isset($newest[$key]) || $label['cts'] > $newest[$key]['cts']) {
                $newest[$key] = $label;
            }
        }

        $events = [];

        foreach ($newest as $label) {
            if (! $verifier->verify($this->labeler, $label)) {
                Log::warning('[atp-appview] Dropped a fetched label whose signature does not verify', ['labeler' => $this->labeler, 'uri' => $label['uri'], 'val' => $label['val']]);

                continue;
            }

            $events[] = new LabelEvent(
                id: 0,
                src: $this->labeler,
                uri: $label['uri'],
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
     * Queue a backfill from every followed labeler, or one, for these accounts.
     *
     * @param  array<int, string>  $dids
     */
    public static function accounts(array $dids, ?string $labeler = null): void
    {
        foreach (self::labelers($labeler) as $did) {
            foreach (array_chunk($dids, self::ACCOUNTS) as $chunk) {
                self::dispatch($did, $chunk);
            }
        }
    }

    /**
     * Queue record URIs for the labelers not known to take wildcards.
     *
     * @param  array<int, string>  $uris
     */
    public static function records(array $uris): void
    {
        $labelers = DB::connection(config('atp-appview.index.connection'))->table('atp_labelers')
            ->whereNotNull('endpoint')->where(fn ($query) => $query->whereNull('wildcards')->orWhere('wildcards', false))
            ->orderBy('did')->pluck('did')->all();

        foreach ($labelers as $did) {
            foreach (array_chunk($uris, LabelQuery::PATTERNS * 5) as $chunk) {
                self::dispatch($did, [], $chunk);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected static function labelers(?string $labeler): array
    {
        return DB::connection(config('atp-appview.index.connection'))->table('atp_labelers')
            ->whereNotNull('endpoint')->when($labeler !== null, fn ($query) => $query->where('did', $labeler))
            ->orderBy('did')->pluck('did')->all();
    }
}
