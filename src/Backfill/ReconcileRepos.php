<?php

namespace SocialDept\AtpAppview\Backfill;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SocialDept\AtpAppview\Index\Reconciler;
use SocialDept\AtpAppview\Ingest\Jetstream\ResolveIdentity;
use SocialDept\AtpAppview\Models\Backfill;
use SocialDept\AtpAppview\Models\BackfillRepo;
use SocialDept\AtpAppview\Models\Identity;
use Throwable;

/**
 * Backfills one collection for some repos without Tap, by reconciling each
 * with its PDS: the repo's collections, then a listing of this one, a
 * hundred records a request. The records go through the ingest pipeline,
 * where BackfillTracker counts them.
 *
 * A repo is marked delivered once read, even with nothing in the
 * collection. One the PDS answers with an account status, such as taken
 * down, or that cannot be read, keeps why instead. Only repos neither
 * delivered nor failed are read, so a retry picks up where a worker left
 * off. The last job to finish ends the backfill.
 */
class ReconcileRepos implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 900;

    /**
     * @param  array<int, string>  $dids
     */
    public function __construct(
        public readonly int $backfill,
        public readonly string $collection,
        public readonly array $dids,
    ) {
    }

    public function handle(Reconciler $reconciler, Backfills $backfills): void
    {
        $backfill = Backfill::query()->find($this->backfill);

        if ($backfill === null || ! $backfill->isRunning()) {
            return;
        }

        $pending = $this->repos()->whereNull('delivered_at')->whereNull('error')->pluck('did')->all();

        // Repos new to the index get an identity, handle and PDS first, as
        // those Jetstream introduces do.
        $unknown = array_values(array_diff($pending, Identity::query()->whereIn('did', $pending)->pluck('did')->all()));

        if ($unknown !== []) {
            (new ResolveIdentity($unknown))->handle();
        }

        foreach ($pending as $did) {
            try {
                $result = $reconciler->reconcile($did, collections: [$this->collection]);
            } catch (Throwable $e) {
                $this->mark($did, ['error' => Str::limit($e->getMessage(), 1000)]);

                continue;
            }

            $this->mark($did, match (true) {
                $result->skipped !== null => ['error' => "Skipped: {$result->skipped}"],
                $result->rev === null && $result->account !== null => ['error' => "Account {$result->account}"],
                default => ['delivered_at' => Carbon::now('UTC')],
            });
        }

        $backfills->finishIfDone($backfill);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function mark(string $did, array $values): void
    {
        // BackfillTracker has already set delivered_at for a repo that had
        // records, so it is only filled, never moved.
        $this->repos()->where('did', $did)->whereNull('delivered_at')->update($values);
    }

    /**
     * @return Builder<BackfillRepo>
     */
    protected function repos(): Builder
    {
        return BackfillRepo::query()->where('backfill_id', $this->backfill)->whereIn('did', $this->dids);
    }
}
