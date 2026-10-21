<?php

namespace SocialDept\AtpAppview\Push;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Push\Contracts\PushPayload;

/**
 * Pushes one new notification to its recipient's browsers, when their
 * choices for its reason want it pushed. Queued, so indexing never waits on
 * a push service.
 */
class SendNotificationPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $notification,
        public readonly string $recipient,
    ) {
    }

    public function handle(Notifications $notifications, NotificationPreferences $preferences, Push $push): void
    {
        $notification = $notifications->one($this->notification);

        // Gone, or hidden since it was made.
        if ($notification === null) {
            return;
        }

        if (! $preferences->wants($this->recipient, (string) $notification['reason'], (string) ($notification['actor']['did'] ?? ''), 'push')) {
            return;
        }

        $payload = app((string) config('atp-appview.push.payload', NotificationPayload::class));
        $message = $payload instanceof PushPayload ? $payload->for($notification) : null;

        if ($message !== null) {
            $push->send($this->recipient, $message);
        }
    }
}
