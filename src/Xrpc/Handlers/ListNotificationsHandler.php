<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * The viewer's notifications, served under
 * atp-appview.notifications.xrpc.list. Grouped by reason and subject unless
 * called with grouped=false, then one at a time, each with its group key.
 *
 * Parameters limit (1 to 100, 50 by default), cursor, grouped and reasons
 * (only these, repeated for several). Answers
 * {notifications, cursor, seenAt}.
 */
class ListNotificationsHandler
{
    use ReadsNotificationViewer;

    public function __construct(
        protected Notifications $notifications,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $did = $this->recipient($request);
        $limit = is_numeric($limit = $request->param('limit')) ? (int) $limit : 50;
        $cursor = $request->param('cursor');
        $cursor = is_scalar($cursor) ? (string) $cursor : null;
        $grouped = ! in_array($request->param('grouped'), [false, 'false', '0', 0], true);

        $reasons = $this->reasons($request);

        $page = $grouped
            ? $this->notifications->groups($did, $limit, $cursor, $reasons)
            : $this->notifications->list($did, $limit, $cursor, $reasons);

        return array_filter($page, fn ($value) => $value !== null);
    }
}
