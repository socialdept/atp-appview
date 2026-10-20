# Push

Part of the [atp-appview docs](../README.md#documentation). Web Push to the browsers people turn it on in, with nothing more to install.

Push is a [feature](features.md), `Features::push()`, off unless listed. Messages are encrypted for each browser (RFC 8291) and signed for its push service (VAPID, RFC 8292) with PHP's own OpenSSL, so no push library is needed. Browsers bring their own push services, from Google, Mozilla, Microsoft and Apple, which need no account.

## Setting it up

Make a key pair once and put it in `.env`:

```bash
php artisan appview:push:keys
```

```dotenv
ATP_APPVIEW_PUSH_PUBLIC_KEY=BP4z9KsN…
ATP_APPVIEW_PUSH_PRIVATE_KEY=LS0tLS1C…
ATP_APPVIEW_PUSH_SUBJECT=mailto:you@example.com
```

Changing the keys later unsubscribes every browser. Without them nothing is sent.

Give the two procedures browsers subscribe through NSIDs in your own namespace:

```php
// config/atp-appview/push.php
'xrpc' => [
    'subscribe' => 'com.example.push.subscribe',
    'unsubscribe' => 'com.example.push.unsubscribe',
],
```

## In the browser

`FrontendAuth::browserConfig()` carries the public key and the procedures once push is set up, and the browser library turns push on and off:

```ts
import { disablePush, enablePush, pushState } from '@socialdept/atp-appview';

await pushState();     // 'unsupported', 'off', 'on' or 'blocked'
await enablePush();    // asks permission, subscribes, tells the server
await disablePush();
```

Each takes the service worker's URL, `/sw.js` by default. The worker is the app's own, and shows each payload:

```js
// public/sw.js
self.addEventListener('push', (event) => {
    const data = event.data?.json() ?? {};
    event.waitUntil(self.registration.showNotification('Someone did something', { body: data.reason, data }));
});
```

On iPhone and iPad, push works once the site is added to the Home Screen, iOS 16.4 and later.

## What is pushed

With the [notifications](notifications.md) feature on, each new notification is pushed, queued, to its recipient's browsers when their choices for its reason want it pushed, from everyone or only accounts they follow. The payload holds its reason, subject, record, time and who it is from, for the worker to put into words. Change it with a class implementing `PushPayload`:

```php
'payload' => App\Push\StatusPayload::class,   // ->for(array $notification): ?array
```

To push something else, send it yourself:

```php
app(Push::class)->send($did, ['type' => 'digest', 'count' => 12]);
```

A payload holds at most 3,993 bytes once encoded.

## Safety

A browser hands over the URL its messages go to, so the server only posts to push services in `push.hosts`: Google's, Mozilla's, Microsoft's and Apple's by default, over https. Without that list anyone could have the server post to any address. A subscription its push service says is gone (404 or 410) is dropped. Only an account can unsubscribe its own browsers.
