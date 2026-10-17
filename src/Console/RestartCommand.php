<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use SocialDept\AtpAppview\Ingest\TapConsumer;

class RestartCommand extends Command
{
    protected $signature = 'appview:restart';

    protected $description = 'Signal running appview:consume workers to exit so their supervisor restarts them';

    public function handle(Cache $cache): int
    {
        $cache->forever(TapConsumer::RESTART_KEY, microtime(true));

        $this->components->info('Broadcasting the consume restart signal.');

        return self::SUCCESS;
    }
}
