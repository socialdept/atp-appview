<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Realtime\Realtime;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Lets the viewer join their own private channel, served under
 * atp-appview.realtime.xrpc.authorize. Input: {socket_id, channel_name}, as
 * Echo sends them. Answers {auth}, as a Pusher-protocol server expects.
 * The viewer comes from a Laravel session or a service auth token.
 */
class AuthorizeChannelHandler
{
    use ReadsNotificationViewer;

    public function __construct(
        protected Realtime $realtime,
    ) {
    }

    /**
     * @return array{auth: string}
     */
    public function __invoke(XrpcRequest $request): array
    {
        $input = (array) $request->input();
        $auth = $this->realtime->authorize(
            $this->recipient($request),
            (string) ($input['socket_id'] ?? ''),
            (string) ($input['channel_name'] ?? ''),
        );

        if ($auth === null) {
            throw new XrpcException('Forbidden', 'This account may not join that channel.', 403);
        }

        return $auth;
    }
}
