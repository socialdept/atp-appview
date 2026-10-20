<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use InvalidArgumentException;
use SocialDept\AtpAppview\Push\Push;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Keeps the viewer's browser subscription for push, served under
 * atp-appview.push.xrpc.subscribe. Input: the browser's PushSubscription as
 * JSON, {endpoint, keys: {p256dh, auth}}. Answers {}.
 */
class SubscribePushHandler
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
        $input = (array) $request->input();
        $keys = (array) ($input['keys'] ?? []);

        try {
            $this->push->subscribe(
                $this->recipient($request),
                (string) ($input['endpoint'] ?? ''),
                (string) ($keys['p256dh'] ?? ''),
                (string) ($keys['auth'] ?? ''),
                $request->http->userAgent(),
            );
        } catch (InvalidArgumentException $e) {
            throw XrpcException::invalidRequest($e->getMessage());
        }

        return [];
    }
}
