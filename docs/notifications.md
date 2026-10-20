# Notifications

Part of the [atp-appview docs](../README.md#documentation). Reasons, notifiers, grouping, seen state, preferences and delivery.

Records that point at an account or a record notify that account. Notifications are a [feature](features.md#features), `Features::notifications()`, on by default. Which ones is configured per collection and field, with the reason each gives, so a like in the app's own lexicon notifies and a Bluesky like never does:

```php
// config/atp-appview/notifications.php
'reasons' => [
    'xyz.statusphere.like' => ['subject.uri' => 'like'],
    'xyz.statusphere.status' => ['reply.parent.uri' => 'reply', 'facets.*.features.*.did' => 'mention'],
    'xyz.statusphere.graph.follow' => ['subject' => 'follow'],
],
'separate' => ['reply', 'mention'],
```

A field holding a DID notifies that account, one holding an AT-URI the account in it. Nobody is notified about their own records. `NotificationIndexer` keeps `atp_notifications` as records are indexed: one row per recipient, removed with its record, untouched when the same record is delivered again. `php artisan appview:notifications:build` adds notifications for records indexed before their reasons were configured, without announcing them. Each takes its record's time, and lists are ordered by time, so they fall where they belong.

Every notification carries its `reason`, its `subject` (the record acted on, or the recipient for a follow) and a `groupKey` made of the two, so "Alice and 4 others liked your status" is one group, and a client showing them one at a time can still join them. Reasons in `separate` give each notification its own group, as replies usually want.

Only notifications whose record shows are read: not deleted, not hidden with its account, not hidden by moderation and not needing review. Lifting an action brings them back.

```php
use SocialDept\AtpAppview\Notifications\Notifications;

$notifications = app(Notifications::class);

$notifications->groups($did, limit: 30, cursor: null);   // grouped, newest group first
$notifications->list($did);                              // one at a time
$notifications->unreadCount($did);
$notifications->markSeen($did);                          // now, or a time. Never moves back
$notifications->people($did, $groupKey);                 // everyone in a group, once each, newest first
```

A group has `groupKey`, `reason`, `subject`, `subjectView`, `count`, `people` (how many different accounts), `unreadCount`, `isRead`, `indexedAt` and `latest`: the newest few notifications (`notifications.latest`, 3 by default), each with its `uri`, `actor` profile and `view`. Subjects and notifying records are hydrated with their collection's view, as the recipient sees them.

The same is served over XRPC under NSIDs in the app's own namespace. A lexicon for each is optional:

```php
'xrpc' => [
    'list' => 'com.example.notification.listNotifications',      // ?grouped=false for one at a time
    'unread_count' => 'com.example.notification.getUnreadCount',
    'update_seen' => 'com.example.notification.updateSeen',     // {seenAt?}
],
```

## Choices

Each account chooses, per reason, whether it shows in the app, whether it is pushed, and whether from everyone or only the accounts they follow. A reason nobody chose for is shown, pushed and from everyone:

```php
use SocialDept\AtpAppview\Notifications\NotificationPreferences;

$preferences = app(NotificationPreferences::class);

$preferences->for($did, ['like', 'reply']);              // ['like' => ['inApp' => true, 'push' => true, 'from' => 'everyone'], ...]
$preferences->set($did, 'like', inApp: false);            // the rest stay as they were
$preferences->set($did, 'reply', from: NotificationPreferences::FOLLOWS);
```

Choices apply as notifications are read, so turning a reason off leaves it out of lists and the unread count, and turning it back on brings back what it held. The app lists the reasons and their labels, since a notifier's reasons only exist in app code.

Pushing is the app's own, and `wantsPush()` answers for a `NotificationCreated` listener:

```php
Event::listen(function (NotificationCreated $event) use ($preferences) {
    if ($preferences->wantsPush($event->recipient, $event->reason, $event->actor)) {
        // send it
    }
});
```

"Only the accounts they follow" reads follows from the [social graph](social-graph.md#social-graph). Without follows configured there, every notification counts as from everyone. (`notifications.follows`, where this lived before the graph, is still read when `graph.follows` is not set.)

The follows must be indexed. A [companion collection](ingest.md#companion-collections) indexes them for every followed repo. To keep only your own users' follows, reconcile that collection for them, on sign-in and on a schedule: `app(Reconciler::class)->reconcile($did, collections: ['app.bsky.graph.follow'])`. A collection the index does not ingest is never followed by Tap, so only those repos are read.

To show only some reasons, such as a tab of mentions, pass them to `groups()`, `list()` or `unreadCount()`, or as `reasons` to the XRPC methods (repeated for several):

```php
$notifications->groups($did, reasons: ['mention']);
```

## Notifiers

When no field of a record names who it notifies, a notifier decides: everyone who replied in a thread, everyone sharing a status, the members of a group. List classes implementing `Notifier` in `notifications.notifiers`. Each names the collections it reads and is given their records as they arrive, already indexed, so its queries see them:

```php
use SocialDept\AtpAppview\Notifications\Contracts\Notifier;
use SocialDept\AtpAppview\Notifications\Notify;

class ThreadNotifier implements Notifier
{
    public function collections(): array
    {
        return ['xyz.statusphere.status'];
    }

    public function notify(array $records): iterable
    {
        foreach ($records as $reply) {
            foreach ($this->participants($reply->record['reply']['root']['uri'] ?? null) as $did) {
                yield new Notify($reply->uri, $did, 'thread', $reply->record['reply']['root']['uri']);
            }
        }
    }
}
```

A `Notify` names the record that notifies, the recipient, the reason and the subject it groups by, which defaults to the recipient. Nobody is notified about their own records, and one a configured reason already gives is kept once.

Only records arriving live are offered. A backfill or a replay keeps what a record gave before, so a notifier can ask about the present ("who has this status now") without old records notifying again. A record changed live is offered again and its notifications brought to what the notifier says now. Deleting it removes them. `appview:notifications:build` does not run notifiers.

## Sending one

For what no record does on its own, such as a scheduled digest or a milestone, send a notification about a record. Its author is the actor, it shows while the record does, and it goes when the record is deleted. Unlike a reason or a notifier, it may notify the record's own author:

```php
$notifications->send($did, 'trending', source: $statusUri, subject: $statusUri);
```

Sending the same reason, subject and record to someone again adds nothing, so a scheduled job can run twice. `send()` returns the new notification's id, or null when nothing was added.

## Delivering them

`NotificationCreated` is dispatched after commit for each new notification whose record shows. Push, email and anything else outside the app hang off it, and `Notifications::one($event->id)` gives it hydrated:

```php
Event::listen(function (NotificationCreated $event) {
    $user = User::firstWhere('did', $event->recipient);
    $user?->notify(new SomethingHappened(app(Notifications::class)->one($event->id)));
});
```
