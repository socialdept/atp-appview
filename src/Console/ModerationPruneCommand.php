<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Models\AccountAction;

class ModerationPruneCommand extends Command
{
    protected $signature = 'appview:moderation:prune
                            {--dry-run : Report what would be pruned without pruning it}';

    protected $description = 'Prune the records of accounts banned longer than atp-appview.moderation.prune_banned_after_days';

    public function handle(): int
    {
        $due = AccountAction::query()->active()
            ->where('action', AccountAction::BAN)
            ->where('prune_cancelled', false)
            ->whereNull('pruned_at')
            ->whereNotNull('prune_at')
            ->where('prune_at', '<=', Carbon::now('UTC'))
            ->get();

        $connection = DB::connection(config('atp-appview.index.connection'));
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $total = 0;

        foreach ($due->groupBy('did') as $did => $bans) {
            $records = $connection->table('atp_records')->where('did', $did)->whereNull('deleted_at');
            $count = (clone $records)->count();
            $total += $count;

            if ($this->option('dry-run')) {
                $this->line("{$did}: {$count} records");

                continue;
            }

            // Tombstoned like a deleted record, so an older event replayed
            // later cannot bring one back.
            $records->update(['deleted_at' => $now, 'record' => null, 'cid' => null]);
            app(RecordValues::class)->drop($connection, [$did]);
            AccountAction::query()->whereIn('id', $bans->pluck('id'))->update(['pruned_at' => $now]);
        }

        $this->components->info(($this->option('dry-run') ? 'Would prune ' : 'Pruned ')."{$total} records of {$due->groupBy('did')->count()} banned accounts.");

        return self::SUCCESS;
    }
}
