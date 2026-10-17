<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Blocklist\BlockOutcome;

class BlockCommand extends Command
{
    protected $signature = 'appview:block
                            {subject? : A DID, or a PDS host such as pds.example or *.example}
                            {--collection= : Only this collection, or a prefix such as app.example.*. Every collection by default}
                            {--note= : Why, for whoever reads the list later}
                            {--purge : Also delete matching records already indexed}
                            {--file= : Read rules from a file, one per line: subject [collection] [# note]}';

    protected $description = 'Keep records from a repo or a PDS host out of the index';

    public function handle(Blocklist $blocklist): int
    {
        try {
            $rules = $this->rules();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rules === []) {
            $this->components->error('Pass a subject, or a file with --file.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($rules as [$subject, $collection, $note]) {
            try {
                $bar = null;

                $outcome = $blocklist->block($subject, $collection, $note, (bool) $this->option('purge'), function (string $stage, int $done, int $total) use (&$bar) {
                    $bar ??= tap($this->output->createProgressBar($total), fn ($bar) => $bar->setFormat('  Resolving PDS hosts %current%/%max% [%bar%]'));
                    $bar->setProgress($done);
                });

                if ($bar !== null) {
                    $bar->finish();
                    $this->newLine();
                }
            } catch (InvalidArgumentException $e) {
                $this->components->error($e->getMessage());
                $failed = true;

                continue;
            }

            $this->report($outcome);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array{string, string|null, string|null}>
     */
    protected function rules(): array
    {
        $file = $this->option('file');

        if (! is_string($file) || $file === '') {
            $subject = $this->argument('subject');

            return is_string($subject) && $subject !== ''
                ? [[$subject, $this->option('collection'), $this->option('note')]]
                : [];
        }

        if (! is_readable($file)) {
            throw new InvalidArgumentException("Cannot read {$file}.");
        }

        $rules = [];

        foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
            [$rule, $note] = array_pad(explode('#', (string) $line, 2), 2, null);
            $parts = preg_split('/\s+/', trim($rule), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($parts === []) {
                continue;
            }

            $note = trim((string) $note);
            $rules[] = [$parts[0], $parts[1] ?? $this->option('collection'), $note !== '' ? $note : $this->option('note')];
        }

        return $rules;
    }

    protected function report(BlockOutcome $outcome): void
    {
        $block = $outcome->block;
        $scope = $block->isBlanket() ? 'every collection' : $block->collection;
        $details = [];

        if ($this->option('purge')) {
            $details[] = number_format($outcome->purged).' '.Str::plural('record', $outcome->purged).' purged';
        }

        if ($outcome->removedFromTap !== []) {
            $details[] = number_format($count = count($outcome->removedFromTap)).' '.Str::plural('repo', $count).' removed from Tap';
        }

        $this->components->twoColumnDetail("{$block->subject} <fg=gray>({$scope})</>", $details === [] ? 'blocked' : implode(', ', $details));

        if ($outcome->tapError !== null) {
            $this->components->warn("Tap was not updated: {$outcome->tapError}. The rule still applies at ingest.");
        }
    }
}
