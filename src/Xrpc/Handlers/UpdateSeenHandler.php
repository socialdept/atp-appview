<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;
use Throwable;

/**
 * Marks the viewer's notifications seen up to seenAt, now when it is left
 * out, served under atp-appview.notifications.xrpc.update_seen. Seen never
 * moves back. Answers {seenAt}.
 */
class UpdateSeenHandler
{
    use ReadsNotificationViewer;

    public function __construct(
        protected Notifications $notifications,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $did = $this->recipient($request);
        $at = $request->input()['seenAt'] ?? null;

        try {
            $at = is_string($at) && $at !== '' ? Carbon::parse($at) : null;
        } catch (Throwable) {
            throw XrpcException::invalidRequest('seenAt must be a datetime.');
        }

        // Never ahead of now, so a client clock running fast cannot hide
        // notifications still to come.
        if ($at !== null && $at->isFuture()) {
            $at = null;
        }

        return ['seenAt' => $this->notifications->markSeen($did, $at)->toIso8601ZuluString()];
    }
}
