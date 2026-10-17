<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
                $result = $reconciler->reconcile($did, $dryRun);
            } catch (Throwable $e) {
                $totals['failed']++;
                $this->components->twoColumnDetail($did, '<fg=red>'.Str::limit($e->getMessage(), 120).'</>');
                $dryRun || $this->remember($did, null, $e->getMessage());

                continue;
            }

            $totals['indexed'] += count($result->indexed);
            $totals['tombstoned'] += count($result->tombstoned);
            $dryRun || $this->remember($did, $result->rev, null);

            $this->components->twoColumnDetail($did, match (true) {
                $result->skipped !== null => "<fg=gray>skipped, {$result->skipped}</>",
                $result->changed() => count($result->indexed).' indexed, '.count($result->tombstoned).' tombstoned',
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
     * Active repos not checked within the interval, least recently checked
     * first.
     *
     * @return array<int, string>
     */
    protected function due(): array
    {
        $limit = (int) ($this->option('limit') ?? config('atp-appview.index.reconcile.batch', 25));
        $before = Carbon::now('UTC')->subHours(max(0, (int) config('atp-appview.index.reconcile.every_hours', 24)));

        return Identity::query()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('reconciled_at')->orWhere('reconciled_at', '<', $before))
            ->orderByRaw('reconciled_at is not null')
            ->orderBy('reconciled_at')
            ->limit(max(1, $limit))
            ->pluck('did')
            ->all();
    }

    protected function remember(string $did, ?string $rev, ?string $error): void
    {
        Identity::query()->whereKey($did)->update([
            'reconciled_at' => Carbon::now('UTC'),
            'reconciled_rev' => $rev,
            'reconcile_error' => $error === null ? null : Str::limit($error, 1000),
        ]);
    }
}
