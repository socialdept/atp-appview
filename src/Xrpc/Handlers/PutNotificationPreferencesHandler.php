<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Changes some of the viewer's choices, served under
 * atp-appview.notifications.xrpc.put_preferences. Takes
 * {preferences: {like: {inApp?, from?, channels?: {push?, ...}}, ...}}, and
 * whatever a reason leaves out stays as it was. The whole input is checked
 * before anything is kept, so a bad reason changes nothing. Answers
 * {preferences} for every reason the viewer can choose for.
 */
class PutNotificationPreferencesHandler
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
        $changes = $this->changes($request->input() ?? []);

        DB::connection(config('atp-appview.index.connection'))->transaction(function () use ($did, $changes) {
            foreach ($changes as $reason => $change) {
                $this->preferences->set($did, $reason, $change['inApp'] ?? null, $change['from'] ?? null, $change['channels'] ?? []);
            }
        });

        return ['preferences' => $this->presentChoices($this->preferences->for($did, NotificationPreferences::reasons()))];
    }

    /**
     * The input, checked: known reasons, known fields, the right types.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{inApp?: bool, from?: string, channels?: array<string, bool>}>
     */
    protected function changes(array $input): array
    {
        if (($extra = array_diff(array_keys($input), ['preferences'])) !== []) {
            throw XrpcException::invalidRequest('Unexpected input: '.implode(', ', $extra).'.');
        }

        $preferences = $input['preferences'] ?? null;

        if (! self::isMap($preferences)) {
            throw XrpcException::invalidRequest('preferences must be an object of choices by reason.');
        }

        $this->ensureChoosable(array_map('strval', array_keys($preferences)));
        $channels = array_keys(NotificationPreferences::channels());
        $changes = [];

        foreach ($preferences as $reason => $change) {
            if (! self::isMap($change)) {
                throw XrpcException::invalidRequest("preferences.{$reason} must be an object.");
            }

            if (($extra = array_diff(array_keys($change), ['inApp', 'from', 'channels'])) !== []) {
                throw XrpcException::invalidRequest("Unexpected field in preferences.{$reason}: ".implode(', ', $extra).'.');
            }

            if (array_key_exists('inApp', $change) && ! is_bool($change['inApp'])) {
                throw XrpcException::invalidRequest("preferences.{$reason}.inApp must be a boolean.");
            }

            if (array_key_exists('from', $change) && ! in_array($change['from'], [NotificationPreferences::EVERYONE, NotificationPreferences::FOLLOWS], true)) {
                throw XrpcException::invalidRequest("preferences.{$reason}.from must be 'everyone' or 'follows'.");
            }

            if (array_key_exists('channels', $change)) {
                if (! self::isMap($change['channels'])) {
                    throw XrpcException::invalidRequest("preferences.{$reason}.channels must be an object.");
                }

                foreach ($change['channels'] as $channel => $on) {
                    if (! in_array((string) $channel, $channels, true)) {
                        throw XrpcException::invalidRequest("No such notification channel: {$channel}.");
                    }

                    if (! is_bool($on)) {
                        throw XrpcException::invalidRequest("preferences.{$reason}.channels.{$channel} must be a boolean.");
                    }
                }
            }

            // Nothing to change keeps the defaults, rather than storing them.
            if ($change !== []) {
                $changes[(string) $reason] = $change;
            }
        }

        return $changes;
    }

    /**
     * A JSON object, which decodes to an array. An empty one cannot be told
     * from an empty list, and changes nothing either way.
     */
    protected static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }
}
