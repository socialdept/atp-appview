<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * How many notifications the viewer has not seen, served under
 * atp-appview.notifications.xrpc.unread_count. Answers {count}.
 */
class GetUnreadCountHandler
{
    use ReadsNotificationViewer;

    public function __construct(
        protected Notifications $notifications,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function __invoke(XrpcRequest $request): array
    {
        return ['count' => $this->notifications->unreadCount($this->recipient($request))];
    }
}
