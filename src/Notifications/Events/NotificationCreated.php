<?php

namespace SocialDept\AtpAppview\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A new notification, dispatched after commit. Push, email and any other
 * channel outside the app hang off this. Notifications::one() gives it
 * hydrated, as the recipient would see it in a list.
 */
class NotificationCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly string $recipient,
        public readonly string $reason,
        public readonly string $subject,
        public readonly string $groupKey,
        public readonly string $actor,
        public readonly string $uri,
        public readonly Carbon $indexedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            (int) $row->id,
            $row->recipient_did,
            $row->reason,
            $row->subject,
            $row->group_key,
            $row->actor_did,
            $row->source_uri,
            Carbon::parse($row->indexed_at, 'UTC'),
        );
    }
}
