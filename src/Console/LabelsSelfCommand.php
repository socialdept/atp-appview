<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use SocialDept\AtpAppview\Labels\SelfLabels;
use SocialDept\AtpAppview\Labels\Visibility;

class LabelsSelfCommand extends Command
{
    protected $signature = 'appview:labels:self
                            {collection? : Only this collection}';

    protected $description = 'Read the self-labels of records indexed before they were kept';

    public function handle(SelfLabels $selfLabels, Visibility $visibility, DatabaseManager $db): int
    {
        if (! config('atp-appview.labels.self_labels', true)) {
            $this->components->warn('Self-labels are off in atp-appview.labels.self_labels.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $changed = $selfLabels->rebuild($db->connection(config('atp-appview.index.connection')), $this->argument('collection'), function (int $read) {
            if ($read % 10000 === 0) {
                $this->components->twoColumnDetail('Records read', number_format($read));
            }
        });

        $visibility->refresh($changed);

        $this->components->info(sprintf('Self-labels changed for %s accounts in %.1f s.', number_format(count($changed)), microtime(true) - $started));

        return self::SUCCESS;
    }
}
