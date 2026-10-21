# Realtime

Part of the [atp-appview docs](../README.md#documentation). Pings to the browser when records, the records pointing at them, and notifications change.

Realtime is a [feature](features.md), `Features::realtime()`, off unless listed. It sends pings over Laravel broadcasting, so set up broadcasting first: [Reverb](https://laravel.com/docs/reverb), or Pusher. Private channels need a Pusher-protocol server, which both are.

A ping says what changed, never what it holds: the record's URI, its collection, the action, and how many changes it stands for. The browser fetches what changed through the app's own methods and pages, which apply blocks, mutes, labels and moderation for that viewer. Sending records over a channel everyone on it hears would show some of them what they should not see.

## Record events

Whether or not realtime is on, the index announces changes as Laravel events, once the batch is committed and every handler has run:

| Event | When | Carries |
| --- | --- | --- |
| `Index\Events\RecordIndexed` | a record was created or updated | `uri`, `did`, `collection`, `rkey`, `action`, `record` |
| `Index\Events\RecordRemoved` | a record was deleted from its repo | `uri`, `did`, `collection`, `rkey`, and the `record` it held |

Only records arriving live dispatch them. Backfills and replays do not, so a backfill never floods listeners.

## Channels

```php
// config/atp-appview/realtime.php
'collections' => ['com.example.post'],
'subjects' => [
    'com.example.like' => ['subject.uri'],
    'com.example.post' => ['reply.parent.uri'],
],
'window' => 1,
'xrpc' => ['authorize' => 'com.example.realtime.authorize'],
```

| Channel | Visibility | Hears |
| --- | --- | --- |
| `appview.collection.{nsid}` | public | new, changed and deleted records of a listed collection |
| `appview.subject.{hash}` | public | records pointing at that record through a listed reference, so counts and replies stay current |
| `appview.account.{hash}` | private, that account only | a new notification, with the notifications feature |

- A public channel only hears about records a signed-out viewer could see: nothing hidden, moderated, awaiting review, or by an account hidden from guests.
- Channel names hash AT-URIs and DIDs, the first 32 hex characters of their SHA-256, since broadcast channel names cannot hold colons or slashes. `Realtime::subjectChannel()` and `accountChannel()` name them on the server, and the browser helpers below do the same.
- Pings on one channel within `window` seconds become one, carrying the latest change and a `count`, so a busy record does not send a message for each like. The flush is a queued job, so run a queue worker. A window of 0 sends each ping at once.

Echo hears each as `.ping`:

```js
{ kind: 'collection', collection: 'com.example.post', uri: 'at://…', action: 'create', count: 3 }
```

## Private channels

An account joins its own channel through the `authorize` procedure, under your own NSID. It is an XRPC method like any other, so it takes a Laravel session (same origin, backend mode or a frontend-mode server session) or a service auth token from the account's PDS (frontend mode). It answers the `{auth}` a Pusher-protocol server expects, signed with the broadcasting connection's key and secret, and refuses any channel but the account's own. Without an `authorize` NSID, private channels are off.

## In the browser

The package's JS names channels and authorizes private ones. Pass the authorizer to Echo, which calls the procedure through the xrpc helper, same origin with a session or through the PDS with a service auth token:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { accountChannel, collectionChannel, realtimeAuthorizer, subjectChannel } from '@socialdept/atp-appview';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    authorizer: realtimeAuthorizer,
});

echo.channel(collectionChannel('com.example.post')).listen('.ping', (ping) => showNewPosts(ping.count));
echo.channel(await subjectChannel(postUri)).listen('.ping', () => reloadCounts(postUri));
echo.private(await accountChannel(did)).listen('.ping', () => refreshUnreadCount());
```

`authorize` comes to the browser in `FrontendAuth::browserConfig()['realtime']`, passed to `configureAtproto()`.

Off, nothing is broadcast and no `authorize` method is served. Records still announce themselves through the events above.
