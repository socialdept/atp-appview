<?php

namespace SocialDept\AtpAppview\Notifications;

/**
 * One notification a Notifier gives: who a record notifies, why, and about
 * what. The subject is what notifications group by with the reason, and
 * defaults to the recipient, as for a follow.
 */
final class Notify
{
    public readonly string $subject;

    public function __construct(
        public readonly string $source,
        public readonly string $recipient,
        public readonly string $reason,
        ?string $subject = null,
    ) {
        $this->subject = $subject ?? $recipient;
    }
}
