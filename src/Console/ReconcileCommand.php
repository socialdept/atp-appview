<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SocialDept\AtpAppview\Index\AccountStatus;
use SocialDept\AtpAppview\Index\Reconciler;
use SocialDept\AtpAppview\Models\Identity;
use Throwable;

class ReconcileCommand extends Command
{
    protected $signature = 'appview:reconcile
                            {did?* : Repos to reconcile, defaults to the active repos due a check}
                            {--limit= : Repos per run, defaults to atp-appview.index.reconcile.batch}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Compare indexed records with each repo\'s PDS and fix what Tap never delivered';

    public function handle(Reconciler $reconciler): int
    {
        $dids = $this->argument('did') ?: $this->due();
        $dryRun = (bool) $this->option('dry-run');
        $totals = ['repos' => 0, 'indexed' => 0, 'tombstoned' => 0, 'failed' => 0];

        foreach ($dids as $did) {
            $totals['repos']++;

            try {
                $result = $dryRun ? $reconciler->reconcile($did, true) : $reconciler->reconcileAndRecord($did);
            } catch (Throwable $e) {
                $totals['failed']++;
                $this->components->twoColumnDetail($did, '<fg=red>'.Str::limit($e->getMessage(), 120).'</>');

                continue;
            }

            $totals['indexed'] += count($result->indexed);
            $totals['tombstoned'] += count($result->tombstoned);

            $this->components->twoColumnDetail($did, match (true) {
                $result->skipped !== null => "<fg=gray>skipped, {$result->skipped}</>",
                $result->account !== null && $result->rev === null => "<fg=yellow>account {$result->account}</>",
                $result->changed() => count($result->indexed).' indexed, '.count($result->tombstoned).' tombstoned'.($result->account ? ", account {$result->account}" : ''),
                $result->account !== null => "account {$result->account}",
                default => '<fg=gray>in step</>',
            });

            if ($this->output->isVerbose()) {
                foreach ($result->indexed as $uri) {
                    $this->line("  + {$uri}");
                }

                foreach ($result->tombstoned as $uri) {
                    $this->line("  - {$uri}");
                }
            }
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s%s repos: %s indexed, %s tombstoned, %s failed.',
            $dryRun ? 'Dry run. ' : '',
            number_format($totals['repos']),
            number_format($totals['indexed']),
            number_format($totals['tombstoned']),
            number_format($totals['failed']),
        ));

        return self::SUCCESS;
    }

    /**
     * Repos not checked within the interval, least recently checked first:
     * active ones, and inactive ones whose PDS could lift the status.
     *
     * @return array<int, string>
     */
    protected function due(): array
    {
        $limit = (int) ($this->option('limit') ?? config('atp-appview.index.reconcile.batch', 25));
        $before = Carbon::now('UTC')->subHours(max(0, (int) config('atp-appview.index.reconcile.every_hours', 24)));

        return Identity::query()
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('status', AccountStatus::LIFTED_BY_PDS))
            ->where(fn ($query) => $query->whereNull('reconciled_at')->orWhere('reconciled_at', '<', $before))
            ->tap(fn ($query) => self::neverCheckedFirst($query))
            ->limit(max(1, $limit))
            ->pluck('did')
            ->all();
    }

    /**
     * Repos never checked first, then the least recently checked, in an order
     * the reconciled_at index can serve. Postgres sorts nulls last unless
     * told otherwise, and the index is built to match. The other databases
     * sort nulls first already.
     */
    public static function neverCheckedFirst(Builder $query): Builder
    {
        return $query->getConnection()->getDriverName() === 'pgsql'
            ? $query->orderByRaw('reconciled_at asc nulls first')
            : $query->orderBy('reconciled_at');
    }
}
