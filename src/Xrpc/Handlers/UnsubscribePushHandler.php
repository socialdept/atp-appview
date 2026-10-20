<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Push\Push;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Forgets the viewer's browser subscription, served under
 * atp-appview.push.xrpc.unsubscribe. Input: {endpoint}. Answers {}.
 */
class UnsubscribePushHandler
{
    use ReadsNotificationViewer;

    public function __construct(
        protected Push $push,
    ) {
    }

    /**
     * @return array<string, never>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $this->push->unsubscribe($this->recipient($request), (string) (((array) $request->input())['endpoint'] ?? ''));

        return [];
    }
}
