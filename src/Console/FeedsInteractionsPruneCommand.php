<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Feeds\FeedInteractions;

class FeedsInteractionsPruneCommand extends Command
{
    protected $signature = 'appview:feeds:prune
                            {--days= : Keep this many days instead of atp-appview.feeds.interactions_days}';

    protected $description = 'Forget feed interactions older than atp-appview.feeds.interactions_days';

    public function handle(FeedInteractions $interactions): int
    {
        $days = $this->option('days');
        $pruned = $interactions->prune($days === null ? null : (int) $days);

        $this->components->info("Forgot {$pruned} feed interactions.");

        return self::SUCCESS;
    }
}
