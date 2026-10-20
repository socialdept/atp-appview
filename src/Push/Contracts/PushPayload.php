<?php

namespace SocialDept\AtpAppview\Push\Contracts;

/**
 * What is pushed for a notification, as Notifications::one() presents it.
 * The app's service worker receives it as JSON. Null pushes nothing.
 */
interface PushPayload
{
    /**
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>|null
     */
    public function for(array $notification): ?array;
}
