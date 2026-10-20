<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * The person whose notifications a request reads: the signed-in viewer,
 * never a service calling as itself.
 */
trait ReadsNotificationViewer
{
    protected function recipient(XrpcRequest $request): string
    {
        $viewer = $request->viewer();

        if ($viewer === null || $viewer->isService()) {
            throw new XrpcException('AuthenticationRequired', 'Notifications belong to a signed-in account.', 401);
        }

        return $viewer->did;
    }

    /**
     * The reasons asked for, or null for all.
     *
     * @return array<int, string>|null
     */
    protected function reasons(XrpcRequest $request): ?array
    {
        $reasons = array_values(array_filter((array) $request->param('reasons', []), fn ($reason) => is_string($reason) && $reason !== ''));

        return $reasons === [] ? null : $reasons;
    }
}
