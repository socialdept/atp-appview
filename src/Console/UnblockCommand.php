<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SocialDept\AtpAppview\Blocklist\Blocklist;

class UnblockCommand extends Command
{
    protected $signature = 'appview:unblock
                            {subject : The DID or PDS host the rule names}
                            {--collection= : The collection the rule names, if it names one}';

    protected $description = 'Remove a block rule and have Tap backfill the repos it covered';

    public function handle(Blocklist $blocklist): int
    {
        try {
            $outcome = $blocklist->unblock((string) $this->argument('subject'), $this->option('collection'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($outcome === null) {
            $this->components->error('No such rule. Run appview:blocks to list them.');

            return self::FAILURE;
        }

        if ($outcome->tapError !== null) {
            $this->components->warn("Rule removed, but Tap was not updated: {$outcome->tapError}. Its records come back on Tap's next resync of each repo.");

            return self::SUCCESS;
        }

        $this->components->info('Rule removed. '.number_format($count = count($outcome->resyncedInTap)).' '.Str::plural('repo', $count).' sent to Tap to backfill again.');

        return self::SUCCESS;
    }
}
