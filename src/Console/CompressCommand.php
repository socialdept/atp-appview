<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\RecordCompression;

class CompressCommand extends Command
{
    protected $signature = 'appview:compress
                            {--recompress : Rewrite rows stored before, so they use the compression too}
                            {--chunk=1000 : Rows per statement when recompressing}
                            {--seconds= : Stop recompressing after this long, to pick up again on the next run}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Apply atp-appview.retention.compression to the stored record bodies, on Postgres';

    public function handle(): int
    {
        $connection = DB::connection(config('atp-appview.index.connection'));
        $compression = new RecordCompression($connection);
        $method = RecordCompression::configured();
        $dryRun = (bool) $this->option('dry-run');

        if (! $compression->applies()) {
            $this->components->info("Record compression is a Postgres setting. Nothing to do on {$connection->getDriverName()}.");

            return self::SUCCESS;
        }

        if (($reason = $compression->unsupported($method)) !== null) {
            $this->components->error($reason);

            return self::FAILURE;
        }

        $changed = array_keys(array_filter($compression->columns(), fn (?string $current) => $current !== $method));

        if (! $dryRun) {
            $compression->apply($method);
        }

        foreach ($compression->columns() as $column => $current) {
            $this->components->twoColumnDetail($column, in_array($column, $changed, true) && $dryRun ? ($current ?? 'default').' → '.($method ?? 'default') : ($current ?? 'default'));
        }

        $this->components->info(sprintf(
            '%d columns %s %s.',
            count($changed),
            $dryRun ? 'would be set to' : 'set to',
            $method ?? 'the default compression',
        ));

        if (! $this->option('recompress')) {
            return self::SUCCESS;
        }

        $target = $method ?? (string) $connection->selectOne('select current_setting(\'default_toast_compression\') as method')->method;
        $seconds = $this->option('seconds');
        $until = $seconds === null ? INF : microtime(true) + max(1, (int) $seconds);
        $result = $compression->recompress($target, max(1, (int) $this->option('chunk')), $until, $dryRun);

        $this->components->info(sprintf(
            '%s rows %s %s.%s',
            number_format($result['rows']),
            $dryRun ? 'would be rewritten to' : 'rewritten to',
            $target,
            $result['done'] ? '' : ' Stopped at the time limit, run again to continue.',
        ));

        return self::SUCCESS;
    }
}
