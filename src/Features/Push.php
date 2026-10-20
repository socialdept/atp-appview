<?php

namespace SocialDept\AtpAppview\Features;

use LogicException;
use SocialDept\AtpAppview\Console\PushKeysCommand;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;
use SocialDept\AtpAppview\Push\Push as WebPushService;
use SocialDept\AtpAppview\Push\SendNotificationPush;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\Handlers\SubscribePushHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\UnsubscribePushHandler;

/**
 * Web Push: browsers subscribe through two XRPC procedures, and each new
 * notification is pushed, queued, to its recipient's browsers when their
 * choices for its reason want it. Adds atp_push_subscriptions and
 * appview:push:keys. Needs no package beyond PHP's OpenSSL.
 *
 * Off, nothing is pushed or kept, and the Push API refuses, naming this
 * feature.
 */
class Push implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('push')
            ->migrations(__DIR__.'/../../database/migrations/push')
            ->commands([PushKeysCommand::class]);

        foreach (['subscribe' => SubscribePushHandler::class, 'unsubscribe' => UnsubscribePushHandler::class] as $key => $handler) {
            if (is_string($nsid = config("atp-appview.push.xrpc.{$key}")) && $nsid !== '') {
                $appview->xrpc($nsid, $handler, 'procedure', Auth::Required);
            }
        }

        // Notifications are what is pushed, when the app has them.
        if (Features::enabled(Notifications::class)) {
            $appview->listen(NotificationCreated::class, function (NotificationCreated $event) {
                if (WebPushService::configured()) {
                    SendNotificationPush::dispatch($event->id, $event->recipient);
                }
            });
        }
    }

    public static function on(): bool
    {
        return Features::enabled(self::class);
    }

    /**
     * @throws LogicException when the feature is off
     */
    public static function ensureOn(): void
    {
        if (! self::on()) {
            throw new LogicException('Push needs the push feature: add Features::push() to atp-appview.features.');
        }
    }
}
