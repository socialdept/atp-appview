<?php

namespace SocialDept\AtpAppview\Push;

use SocialDept\AtpAppview\Push\Contracts\PushPayload;

/**
 * The default payload: the notification's reason, subject, record and
 * time, and who it is from, small enough for any push service. The app's
 * service worker turns it into words.
 */
class NotificationPayload implements PushPayload
{
    public function for(array $notification): ?array
    {
        $actor = (array) ($notification['actor'] ?? []);

        return [
            'type' => 'notification',
            'reason' => $notification['reason'] ?? null,
            'subject' => $notification['subject'] ?? null,
            'uri' => $notification['uri'] ?? null,
            'groupKey' => $notification['groupKey'] ?? null,
            'actor' => array_intersect_key($actor, array_flip(['did', 'handle', 'displayName', 'avatar'])),
            'indexedAt' => $notification['indexedAt'] ?? null,
        ];
    }
}
