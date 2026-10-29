<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Xrpc\XrpcException;

/**
 * Only the reasons the app offers can be read or chosen over XRPC, so a
 * client cannot store choices for reasons that never notify.
 */
trait ReadsNotificationPreferences
{
    /**
     * @param  array<int, string>  $reasons
     */
    protected function ensureChoosable(array $reasons): void
    {
        $unknown = array_diff($reasons, NotificationPreferences::reasons());

        if ($unknown !== []) {
            throw XrpcException::invalidRequest('No such notification reason: '.implode(', ', $unknown).'.');
        }
    }
}
