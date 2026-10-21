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

Each account chooses, per reason, whether it shows in the app, whether from everyone or only the accounts they follow, and whether each of the app's channels carries it. A reason nobody chose for is shown, from everyone, and on each channel as configured.

Channels are ways the app delivers notifications outside its own list, such as push or email. The package keeps the choices, and delivery is the app's:

```php
// config/atp-appview/notifications.php
'channels' => ['push' => true, 'email' => false],
```

```php
use SocialDept\AtpAppview\Notifications\NotificationPreferences;

$preferences = app(NotificationPreferences::class);

$preferences->for($did, ['like', 'reply']);              // ['like' => ['inApp' => true, 'from' => 'everyone', 'channels' => ['push' => true, 'email' => false]], ...]
$preferences->set($did, 'like', inApp: false);            // the rest stay as they were
$preferences->set($did, 'reply', from: NotificationPreferences::FOLLOWS);
$preferences->set($did, 'reply', channels: ['email' => true]);   // other channels stay as they were
```

Shown and from apply as notifications are read, so turning a reason off leaves it out of lists and the unread count, and turning it back on brings back what it held. The app lists the reasons and their labels, since a notifier's reasons only exist in app code. Setting a channel the app does not configure throws.

Before sending on a channel, a `NotificationCreated` listener asks `wants()`, which also holds "only the accounts they follow":

```php
Event::listen(function (NotificationCreated $event) use ($preferences) {
    if ($preferences->wants($event->recipient, $event->reason, $event->actor, 'email')) {
        // send it, with Notifications::one($event->id) for the hydrated notification
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

## Activity subscriptions

An account can ask to hear whenever another account writes, as Bluesky's "Keep me posted" does. The app names the kinds of activity, each a collection and the reason its notifications take, optionally requiring a path to be set (`with`) or unset (`without`):

```php
// config/atp-appview/notifications.php
'activity' => [
    'post' => ['collection' => 'com.example.post', 'without' => 'reply', 'reason' => 'subscribed-post'],
    'reply' => ['collection' => 'com.example.post', 'with' => 'reply', 'reason' => 'subscribed-reply'],
],
```

```php
use SocialDept\AtpAppview\Notifications\ActivitySubscriptions;

$subscriptions = app(ActivitySubscriptions::class);

$subscriptions->set($alice, $bob, ['post']);   // replaces Alice's choices for Bob, [] unsubscribes
$subscriptions->for($alice, $bob);            // ['post']
$subscriptions->subscriptions($alice);        // everyone Alice hears about, with the kinds
```

Once any kind is configured, `ActivityNotifier` joins the notifiers by itself: each matching record a subscribed account writes live notifies each subscriber, grouped on its own. Subscriptions are private, in `atp_activity_subscriptions`, and blocks, mutes and preferences apply when notifications are read, as for any other reason. A kind removed from config stops applying to existing subscriptions.

## Sending one

For what no record does on its own, such as a scheduled digest or a milestone, send a notification about a record. Its author is the actor, it shows while the record does, and it goes when the record is deleted. Unlike a reason or a notifier, it may notify the record's own author:

```php
$notifications->send($did, 'trending', source: $statusUri, subject: $statusUri);
```

Sending the same reason, subject and record to someone again adds nothing, so a scheduled job can run twice. `send()` returns the new notification's id, or null when nothing was added.

## Delivering them

Delivery outside the app, such as Web Push or email, is the app's. `NotificationCreated` is dispatched after commit for each new notification whose record shows, and `Notifications::one($event->id)` gives it hydrated, as the recipient would see it. Ask [`wants()`](#choices) first, so each account's choices for the channel hold:

```php
Event::listen(function (NotificationCreated $event) {
    $user = User::firstWhere('did', $event->recipient);
    $user?->notify(new SomethingHappened(app(Notifications::class)->one($event->id)));
});
```
