<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;

class RoutesCommand extends Command
{
    protected $signature = 'appview:routes';

    protected $description = 'List the XRPC methods this application serves';

    public function handle(XrpcRegistry $registry): int
    {
        $methods = $registry->all();

        if ($methods === []) {
            $this->components->warn('No XRPC handlers are registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['NSID', 'HTTP', 'Auth', 'Lexicon', 'Handler'],
            array_map(fn ($method) => [
                $method->nsid,
                $method->httpMethod(),
                $method->auth->value,
                $method->lexicon !== null ? 'yes' : 'no',
                $method->handler,
            ], array_values($methods)),
        );

        return self::SUCCESS;
    }
}
