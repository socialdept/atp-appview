<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Push\WebPush;
use SocialDept\AtpClient\Crypto\P256;

class PushKeysCommand extends Command
{
    protected $signature = 'appview:push:keys';

    protected $description = 'Make a VAPID key pair for Web Push';

    public function handle(): int
    {
        $key = P256::create();
        $public = WebPush::publicPoint($key);

        $this->line('Put these in .env. Changing them later unsubscribes every browser.');
        $this->newLine();
        $this->line('ATP_APPVIEW_PUSH_PUBLIC_KEY='.WebPush::base64url($public));
        $this->line('ATP_APPVIEW_PUSH_PRIVATE_KEY='.$key->privateB64());
        $this->line('ATP_APPVIEW_PUSH_SUBJECT=mailto:you@example.com');

        return self::SUCCESS;
    }
}
