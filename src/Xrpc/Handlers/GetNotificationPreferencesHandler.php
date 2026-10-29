<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * The viewer's choices per reason, defaults filled in, served under
 * atp-appview.notifications.xrpc.get_preferences. Every reason they can
 * choose for, or only the reasons given (repeated for several). Answers
 * {preferences: {like: {inApp, from, channels}, ...}}.
 */
class GetNotificationPreferencesHandler
{
    use ReadsNotificationPreferences;
    use ReadsNotificationViewer;

    public function __construct(
        protected NotificationPreferences $preferences,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $did = $this->recipient($request);
        $reasons = $this->reasons($request);

        if ($reasons !== null) {
            $this->ensureChoosable($reasons);
        }

        return ['preferences' => $this->presentChoices($this->preferences->for($did, $reasons ?? NotificationPreferences::reasons()))];
    }
}
