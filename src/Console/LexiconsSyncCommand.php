<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Lexicons\FollowResult;
use SocialDept\AtpAppview\Lexicons\LexiconFollower;
use Throwable;

class LexiconsSyncCommand extends Command
{
    protected $signature = 'appview:lexicons:sync
                            {nsid?* : Lexicons to sync, defaults to every followed one}
                            {--force : Put a new version in use even when it would break the one in use}';

    protected $description = 'Fetch followed lexicons from their authors and put new versions in use';

    public function handle(LexiconFollower $follower): int
    {
        $targets = $this->argument('nsid') ?: LexiconFollower::followed();
        $failed = false;

        foreach ($targets as $nsid) {
            try {
                $result = $follower->sync($nsid, (bool) $this->option('force'));
            } catch (Throwable $e) {
                $failed = true;
                $this->components->twoColumnDetail($nsid, '<fg=red>'.$e->getMessage().'</>');

                continue;
            }

            $this->components->twoColumnDetail($nsid, match ($result->outcome) {
                FollowResult::ADDED => '<fg=green>added</>',
                FollowResult::UPDATED => "<fg=green>updated to revision {$result->revision}</>",
                FollowResult::REFUSED => '<fg=red>new version held back, it would break the one in use</>',
                FollowResult::NOT_PUBLISHED => '<fg=yellow>not published</>',
                default => '<fg=gray>unchanged</>',
            });

            foreach ($result->issues as $issue) {
                $this->line("    <fg=red>[{$issue->name}]</> {$issue->message}");
            }
        }

        // Every version in use, written again, so a fresh server has them all.
        $written = $follower->writeAll();
        $this->newLine();
        $this->components->info("{$written} lexicons written to ".LexiconFollower::path().'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
