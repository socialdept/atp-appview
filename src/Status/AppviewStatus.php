<?php

namespace SocialDept\AtpAppview\Status;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\IndexStats;
use SocialDept\AtpAppview\Index\ReconcileErrors;
use SocialDept\AtpAppview\Index\RecordValidation;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Ingest\Heartbeats;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\Jetstream\JetstreamSource;
use SocialDept\AtpAppview\Ingest\Labels\LabelSource;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Lexicons\LexiconFollower;
use SocialDept\AtpAppview\Models\Block;
use SocialDept\AtpAppview\Models\FollowedLexicon;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use Throwable;

/**
 * What an operator wants to know about the AppView, for a status page or an
 * admin panel: is the network flowing, what does the index hold, and what is
 * it doing to stay correct.
 *
 * Every figure is cheap to ask for. Whole-index counts come from IndexStats,
 * which appview:stats keeps on a schedule, and the rest is cached briefly, so
 * a page polling every few seconds costs little.
 */
class AppviewStatus
{
    protected const CACHE = 'atp-appview:status:';

    public function __construct(
        protected Cache $cache,
        protected IndexStats $stats,
        protected IngestStats $ingest,
        protected Heartbeats $heartbeats,
    ) {
    }

    /**
     * Tap's health and its cheap counters, asked with a short timeout so a
     * slow Tap cannot hold up a page. record-count reads Tap's whole records
     * table, so it is never asked for.
     *
     * @return array{healthy: bool, error?: string, repos?: int|null, outbox?: int|null, resync?: int|null, cursor?: int|null}
     */
    public function tap(): array
    {
        return $this->cache->remember(self::CACHE.'tap', 5, function () {
            $tap = new TapAdmin((string) config('atp-appview.ingest.admin_url'), config('atp-appview.ingest.password'), timeout: 3);

            try {
                $stat = fn (string $name) => array_values($tap->stats($name))[0] ?? null;

                return [
                    'healthy' => $tap->healthy(),
                    'repos' => $stat('repo-count'),
                    'outbox' => $stat('outbox-buffer'),
                    'resync' => $stat('resync-buffer'),
                    'cursor' => $tap->stats('cursors')['firehose'] ?? null,
                ];
            } catch (Throwable $e) {
                return ['healthy' => false, 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Where records come from, tap or jetstream, and where labels come from,
     * tack or streams.
     *
     * @return array{records: string, labels: string}
     */
    public function sources(): array
    {
        return [
            'records' => (string) config('atp-appview.ingest.source', 'tap'),
            'labels' => (string) config('atp-appview.labels.source', 'tack'),
        ];
    }

    /**
     * The websocket sources: whether each process is running, as its
     * heartbeat says, and where its cursors stand. A cursor is saved with
     * the batch it ends, so savedAt is when the source last wrote. Jetstream
     * carries account and identity events from the whole network, so its
     * cursor moves every few seconds. A labeler can go quiet for hours.
     *
     * @return array{jetstream: array{running: bool, seenAt: string|null, cursor: int|null, savedAt: string|null}, labels: array{running: bool, seenAt: string|null, following: int|null, labelers: list<array{did: string, cursor: int, savedAt: string}>}}
     */
    public function streams(): array
    {
        $cursors = $this->cache->remember(self::CACHE.'cursors', 5, fn () => $this->connection()->table('atp_ingest_cursors')
            ->get(['source', 'cursor', 'updated_at'])
            ->map(fn ($row) => ['source' => $row->source, 'cursor' => (int) $row->cursor, 'savedAt' => Carbon::parse($row->updated_at, 'UTC')->toIso8601ZuluString()])
            ->all());

        $jetstream = collect($cursors)->firstWhere('source', JetstreamSource::CURSOR);

        return [
            'jetstream' => [
                'running' => $this->heartbeats->running(JetstreamSource::HEARTBEAT),
                'seenAt' => $this->heartbeats->seenAt(JetstreamSource::HEARTBEAT)?->toIso8601ZuluString(),
                'cursor' => $jetstream['cursor'] ?? null,
                'savedAt' => $jetstream['savedAt'] ?? null,
            ],
            'labels' => [
                'running' => $this->heartbeats->running(LabelSource::HEARTBEAT),
                'seenAt' => $this->heartbeats->seenAt(LabelSource::HEARTBEAT)?->toIso8601ZuluString(),
                'following' => $this->heartbeats->state(LabelSource::HEARTBEAT)['following'] ?? null,
                'labelers' => collect($cursors)
                    ->filter(fn (array $row) => str_starts_with($row['source'], LabelSource::CURSOR_PREFIX))
                    ->map(fn (array $row) => ['did' => substr($row['source'], strlen(LabelSource::CURSOR_PREFIX)), 'cursor' => $row['cursor'], 'savedAt' => $row['savedAt']])
                    ->sortByDesc('savedAt')
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * What the consumer received from Tap lately. Backfilled records arrive
     * marked live: false, which is how a running backfill shows.
     *
     * @return array{backfilling: bool, lastBackfillAt: string|null, lastLiveAt: string|null, perMinute: array{backfill: float, live: float, identity: float}}
     */
    public function ingest(): array
    {
        return [
            'backfilling' => $this->ingest->backfilling(),
            'lastBackfillAt' => $this->ingest->lastBackfillAt()?->toIso8601ZuluString(),
            'lastLiveAt' => $this->ingest->lastLiveAt()?->toIso8601ZuluString(),
            'perMinute' => $this->ingest->perMinute(5),
        ];
    }

    /**
     * Rows written to the index in the last minute and the last five, and the
     * latest write. A resync rewrites rows too, so this is index activity,
     * not network activity: see recentRecords() for that.
     *
     * @return array{minute: int, fiveMinutes: int, lastIndexedAt: string|null}
     */
    public function writes(): array
    {
        return $this->cache->remember(self::CACHE.'writes', 5, fn () => [
            'minute' => Record::withTrashed()->withHidden()->where('indexed_at', '>=', Carbon::now()->subMinute())->count(),
            'fiveMinutes' => Record::withTrashed()->withHidden()->where('indexed_at', '>=', Carbon::now()->subMinutes(5))->count(),
            // The latest per collection, each an index lookup. A plain max()
            // reads the whole table, since indexed_at leads no index.
            'lastIndexedAt' => collect($this->collectionNames())
                ->map(fn (string $nsid) => Record::withTrashed()->withHidden()->where('collection', $nsid)->max('indexed_at'))
                ->filter()
                ->max(),
        ]);
    }

    /**
     * Records made in the last few hours by their own timestamp, which says
     * what the network is doing. Counted a collection at a time, since
     * sort_at is only indexed within one.
     */
    public function recentRecords(int $hours = 24): int
    {
        return $this->cache->remember(self::CACHE."recent:{$hours}", 60, fn () => collect($this->collectionNames())
            ->sum(fn (string $nsid) => Record::query()->where('collection', $nsid)->where('sort_at', '>=', Carbon::now()->subHours($hours))->count()));
    }

    /**
     * Records, hidden records, tombstones and repos per collection, as
     * appview:stats last counted them, with every ingested collection listed
     * even before it holds anything.
     *
     * @return list<array{nsid: string, records: int, hidden: int, tombstones: int, repos: int}>
     */
    public function collections(): array
    {
        $counts = $this->counts()->collections();
        $empty = ['records' => 0, 'hidden' => 0, 'tombstones' => 0, 'repos' => 0];

        return collect($this->collectionNames())
            ->merge(array_keys($counts))
            ->unique()
            ->map(fn (string $nsid) => ['nsid' => $nsid, ...($counts[$nsid] ?? $empty)])
            ->values()
            ->all();
    }

    /**
     * @return array{records: int, hidden: int, tombstones: int, repos: int}
     */
    public function totals(): array
    {
        return $this->counts()->totals();
    }

    /**
     * When the counts were taken.
     */
    public function countedAt(): ?Carbon
    {
        return $this->stats->countedAt();
    }

    /**
     * The index's size on disk, on Postgres. Null elsewhere.
     *
     * @return array{records: int, identities: int}|null
     */
    public function storage(): ?array
    {
        $connection = $this->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return null;
        }

        return $this->cache->remember(self::CACHE.'storage', 60, fn () => [
            'records' => (int) $connection->scalar('select pg_total_relation_size(?)', ['atp_records']),
            'identities' => (int) $connection->scalar('select pg_total_relation_size(?)', ['atp_identities']),
        ]);
    }

    /**
     * Accounts by status: active, and each inactive status Tap or a PDS
     * reported.
     *
     * @return array{total: int, active: int, inactive: array<string, int>}
     */
    public function accounts(): array
    {
        return $this->cache->remember(self::CACHE.'accounts', 30, fn () => [
            'total' => Identity::query()->count(),
            'active' => Identity::query()->where('active', true)->count(),
            'inactive' => Identity::query()->where('active', false)
                ->selectRaw("coalesce(status, 'inactive') as status, count(*) as total")
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($n) => (int) $n)
                ->all(),
        ]);
    }

    /**
     * How much of the network appview:reconcile has checked lately, and the
     * errors it hit, grouped by kind, most common first.
     *
     * @return array{due: int, checked: int, checkedRecently: int, everyHours: int, errors: int, errorKinds: array<string, int>}
     */
    public function reconciliation(): array
    {
        return $this->cache->remember(self::CACHE.'reconciliation', 15, function () {
            $everyHours = (int) config('atp-appview.index.reconcile.every_hours', 24);

            $kinds = Identity::query()->whereNotNull('reconcile_error')->pluck('reconcile_error')
                ->map(fn (string $error) => ReconcileErrors::kind($error))
                ->countBy()
                ->sortDesc()
                ->all();

            return [
                'due' => Identity::query()->where('active', true)->count(),
                'checked' => Identity::query()->whereNotNull('reconciled_at')->count(),
                'checkedRecently' => Identity::query()->where('reconciled_at', '>=', Carbon::now('UTC')->subHours($everyHours))->count(),
                'everyHours' => $everyHours,
                'errors' => array_sum($kinds),
                'errorKinds' => $kinds,
            ];
        });
    }

    /**
     * Followed lexicons, how many are in use, how many have a newer version
     * held back, and the oldest last check.
     *
     * @return array{followed: int, synced: int, heldBack: int, checkedAt: string|null}
     */
    public function lexicons(): array
    {
        $followed = LexiconFollower::followed();
        $rows = FollowedLexicon::query()->whereIn('nsid', $followed)->get(['nsid', 'pending_cid', 'fetched_at']);

        return [
            'followed' => count($followed),
            'synced' => $rows->count(),
            'heldBack' => $rows->whereNotNull('pending_cid')->count(),
            'checkedAt' => $rows->min('fetched_at')?->toIso8601ZuluString(),
        ];
    }

    /**
     * The validation mode, and invalid records seen per collection.
     *
     * @return array{mode: string, invalid: array<string, int>}
     */
    public function validation(): array
    {
        $validation = app(RecordValidation::class);

        return [
            'mode' => RecordValidation::mode(),
            'invalid' => collect($this->collectionNames())
                ->mapWithKeys(fn (string $nsid) => [$nsid => $validation->count($nsid)])
                ->all(),
        ];
    }

    /**
     * Block rules, records they dropped at ingest, and the latest drop.
     *
     * @return array{rules: int, dropped: int, lastDroppedAt: string|null}
     */
    public function blocklist(): array
    {
        $last = Block::query()->max('last_blocked_at');

        return [
            'rules' => Block::query()->count(),
            'dropped' => (int) Block::query()->sum('blocked_count'),
            'lastDroppedAt' => $last === null ? null : Carbon::parse($last, 'UTC')->toIso8601ZuluString(),
        ];
    }

    public function forget(): void
    {
        foreach (['tap', 'cursors', 'writes', 'storage', 'accounts', 'reconciliation', 'recent:24'] as $key) {
            $this->cache->forget(self::CACHE.$key);
        }
    }

    /**
     * The counts, taken now if they never were, so a fresh install shows
     * numbers before the scheduler first runs appview:stats.
     */
    protected function counts(): IndexStats
    {
        if ($this->stats->countedAt() === null) {
            $this->stats->refresh();
        }

        return $this->stats;
    }

    /**
     * @return array<int, string>
     */
    protected function collectionNames(): array
    {
        return Collections::all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
