<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use RuntimeException;
use SocialDept\AtpAppview\Lexicons\LexiconFollower;

class LexiconsAcceptCommand extends Command
{
    protected $signature = 'appview:lexicons:accept {nsid : The lexicon whose held-back version to put in use}';

    protected $description = 'Put a held-back version of a followed lexicon in use, despite its breaking changes';

    public function handle(LexiconFollower $follower): int
    {
        try {
            $revision = $follower->accept((string) $this->argument('nsid'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$this->argument('nsid')} is now at revision {$revision}.");

        return self::SUCCESS;
    }
}
