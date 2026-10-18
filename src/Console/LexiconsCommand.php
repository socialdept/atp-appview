<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Lexicons\LexiconFollower;

class LexiconsCommand extends Command
{
    protected $signature = 'appview:lexicons';

    protected $description = 'List the published lexicons this AppView follows';

    public function handle(LexiconFollower $follower): int
    {
        $followed = LexiconFollower::followed();

        if ($followed === []) {
            $this->components->info('No lexicons are followed. List them in atp-appview.lexicons.follow.');

            return self::SUCCESS;
        }

        foreach ($followed as $nsid) {
            $row = $follower->current($nsid);

            $this->components->twoColumnDetail($nsid, match (true) {
                $row === null => '<fg=yellow>not synced yet</>',
                $row->pending_schema !== null => "<fg=red>revision {$row->revision}, a newer version is held back</>",
                default => "<fg=green>revision {$row->revision}</> <fg=gray>from {$row->did}, checked {$row->fetched_at}</>",
            });

            if ($row?->pending_issues !== null) {
                foreach (json_decode($row->pending_issues, true) ?: [] as $issue) {
                    $this->line("    <fg=red>[{$issue['name']}]</> {$issue['message']}");
                }

                $this->line("    Run appview:lexicons:accept {$nsid} to put it in use anyway.");
            }
        }

        $history = DB::connection(config('atp-appview.index.connection'))->table('atp_lexicon_versions')->count();
        $this->newLine();
        $this->line("  <fg=gray>{$history} versions in atp_lexicon_versions. Files in ".LexiconFollower::path().'</>');

        return self::SUCCESS;
    }
}
