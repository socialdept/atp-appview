<?php

namespace SocialDept\AtpAppview\Features;

use LogicException;
use SocialDept\AtpAppview\Console\NotificationsBuildCommand;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Notifications\NotificationIndexer;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\Handlers\GetNotificationPreferencesHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\GetUnreadCountHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\ListNotificationsHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\PutNotificationPreferencesHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\UpdateSeenHandler;

/**
 * Notifications: reasons, notifiers, grouping, seen state, preferences and
 * the XRPC methods, kept by NotificationIndexer as records are indexed.
 * Adds the atp_notifications tables and appview:notifications:build.
 *
 * Off, nothing is kept, no methods are served, and the Notifications and
 * NotificationPreferences APIs refuse, naming this feature.
 */
class Notifications implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('notifications')
            ->migrations(__DIR__.'/../../database/migrations/notifications')
            ->handler(NotificationIndexer::class)
            // A pruned record notifies no one, and its rows would only be
            // skipped by every read.
            ->afterPrune(fn ($connection, array $uris) => $connection->table('atp_notifications')->whereIn('source_uri', $uris)->delete())
            ->commands([NotificationsBuildCommand::class]);

        // Each method under the NSID the app gives it, in its own namespace.
        $methods = [
            'list' => [ListNotificationsHandler::class, 'query'],
            'unread_count' => [GetUnreadCountHandler::class, 'query'],
            'update_seen' => [UpdateSeenHandler::class, 'procedure'],
            'get_preferences' => [GetNotificationPreferencesHandler::class, 'query'],
            'put_preferences' => [PutNotificationPreferencesHandler::class, 'procedure'],
        ];

        foreach ($methods as $key => [$handler, $type]) {
            if (is_string($nsid = config("atp-appview.notifications.xrpc.{$key}")) && $nsid !== '') {
                $appview->xrpc($nsid, $handler, $type, Auth::Required);
            }
        }
    }

    public static function on(): bool
    {
        return Features::enabled(self::class);
    }

    /**
     * @throws LogicException when the feature is off
     */
    public static function ensureOn(): void
    {
        if (! self::on()) {
            throw new LogicException('Notifications need the notifications feature: add Features::notifications() to atp-appview.features.');
        }
    }
}
