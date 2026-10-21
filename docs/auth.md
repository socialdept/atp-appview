# Auth

Part of the [atp-appview docs](../README.md#documentation). Frontend and backend mode, sign-in from the browser, and the atp guard.

Where the user's OAuth tokens live decides how records get written.

| | Frontend mode (default) | Backend mode |
|---|---|---|
| Tokens | In the browser, with a non-extractable DPoP key | On the server |
| Writing records | The browser calls `com.atproto.repo.*` on its own PDS | Default procedures, through the server's session |
| Reaching the AppView | Through the PDS, with `atproto-proxy` and service auth | The session cookie, or service auth |
| Seeing your own write | `sync_record`, or show it optimistically | Indexed as part of the write |

Frontend mode is the default and the safer choice: the server stores no tokens, and it is how atproto apps such as Bluesky's own work. Choose backend mode only when the app must act for users while they are away, such as scheduled publishing. `atp-appview.auth.mode` records the choice.

## Frontend sign-in

In frontend mode the package ships both halves of sign-in: routes on the server and a browser library that holds the session. The browser keeps the OAuth tokens and a non-extractable DPoP key in IndexedDB, runs the authorization request, the token exchange and refreshes itself, and writes straight to its own PDS. The server never holds a token. It keeps the PKCE verifier between redirect and callback, signs short-lived client assertions so the app stays a confidential client, resolves accounts, and starts an ordinary session from a service auth token the user's own PDS signs.

## Server

The routes register in frontend mode. Set the method a service auth token must be bound to, under a domain you control:

```dotenv
ATP_APPVIEW_SESSION_METHOD=com.example.createSession
ATP_SERVICE_AUTH_AUDIENCE=did:web:example.com
```

| Route | Name | Purpose |
|---|---|---|
| `POST /oauth/prepare` | `atp.oauth.prepare` | Start a sign-in from a provider, a handle, or a known DID |
| `POST /oauth/client-assertion` | `atp.oauth.client-assertion` | Sign an assertion for the flow in progress or the user's own server |
| `GET /oauth/callback` | `atp.oauth.callback` | Check `state` and `iss`, hold the code, redirect to `complete_route` |
| `POST /oauth/flow` | `atp.oauth.flow` | Hand the browser the held flow, once |
| `GET /oauth/account` | `atp.oauth.account` | Resolve an account's PDS, issuer and verified handle |
| `POST /session` | `atp.session.store` | Start a session from a service auth token |
| `DELETE /session` | `atp.session.destroy` | End the session |

Two pages sit around sign-in: the login page and the page the callback finishes on, which runs `handleCallback()`. Give the package a callback for each and it serves them as `login` (guests only) and `login/complete`, returning whatever the callback returns. The package needs no view layer, so Inertia stays your dependency, not this package's:

```php
// AppServiceProvider::boot()
FrontendAuth::loginView(fn () => Inertia::render('auth/Login', [
    'providers' => FrontendAuth::providers(),
]));

FrontendAuth::completeView(fn () => Inertia::render('auth/Callback'));
```

A Blade view works the same way. Without a callback the package registers no page, and your own routes named by `login_route` and `complete_route` take over.

Users are found or created by DID like the `atp` guard does, and `handle_column` is kept current. Share the browser's settings with every page:

```php
'atproto' => FrontendAuth::browserConfig(),
```

A published config file replaces the package's `auth` block as a whole, so copy the `frontend` block into it when upgrading.

## Browser

The library ships in `resources/js`. Alias it in Vite and TypeScript:

```ts
// vite.config.ts
resolve: {
    alias: {
        '@socialdept/atp-appview': fileURLToPath(new URL('./vendor/socialdept/atp-appview/resources/js', import.meta.url)),
    },
},
```

```json
"paths": { "@socialdept/atp-appview": ["./vendor/socialdept/atp-appview/resources/js/index.ts"] }
```

Configure it once with the package's routes, as Wayfinder helpers or anything with a `url()` method, then keep the two sessions in step on every page:

```ts
import { configureAtproto, reconcile, syncSessions } from '@socialdept/atp-appview';
import * as oauth from '@/routes/atp/oauth';
import * as session from '@/routes/atp/session';

configureAtproto({
    routes: {
        prepare: oauth.prepare,
        clientAssertion: oauth.clientAssertion,
        flow: oauth.flow,
        account: oauth.account,
        startSession: session.store,
        endSession: session.destroy,
    },
    ...page.props.atproto,
});

await reconcile(page.props.auth.user);
```

| Call | What it does |
|---|---|
| `signIn({ provider } \| { handle } \| { did }, returnTo?)` | Leave for the authorization server |
| `signInPopup(did)` | Sign a known account back in through a popup, leaving the page as it is. Call it straight from a click |
| `handleCallback()` | On the complete page: exchange the code, check the account, store the session, start a server session |
| `pdsFetch(did, path, init)` | A request to the account's own PDS with DPoP, retried once after a rejected token |
| `withSession(action)` | Run an action, and if the session turns out dead, wait for the user to sign back in and run it once more |
| `reconcile(user)`, `syncSessions(options)` | Keep the server session in step with the browser's |
| `reconnectState()`, `onReconnectChange(listener)` | Whether to offer or ask for a sign-in, for a banner or a dialog |
| `signOut()` | Revoke the refresh token, forget the key and tokens, end the server session |

## Keeping sessions in step

The browser session is the source of truth. The server session can be rebuilt from it at any time, so the server never logs anyone out when tokens die.

- **Refresh** runs under a per-account Web Lock and re-reads storage inside it. Refresh tokens are single use, so another tab may have refreshed first.
- **Only a dead grant ends a session**: `invalid_grant`, `invalid_client`, `unauthorized_client`, a missing key, or a token the PDS rejects twice. Network failures, server errors and unknown answers leave it alone.
- **A dead session keeps its account** without tokens, so its handle is at hand to sign back in, and every change is announced to the other tabs.
- **A server session that ended** is rebuilt from the browser's while its access token still works. Otherwise the page offers to reconnect.
- **An action that hits a dead session** is not lost. `withSession()` raises a dialog, the user signs back in through a popup, and the action runs again. It only retries a request that never took effect.
- **Signing back in** prepares with the account's DID, and the browser refuses a sign-in that lands on another account.

## Session lifetime

Laravel's session lifetime, `SESSION_LIFETIME` in minutes, defaults to 120, so an app left alone for two hours forgets the user while their atproto session is still good. Set it from the kind of OAuth client the app is, which its client metadata declares, not the mode:

- **Confidential**: the metadata declares `private_key_jwt`, which needs a client key (`ATP_OAUTH_PRIVATE_KEY`). Requests to the authorization server are signed: by the server itself in backend mode, and in frontend mode by short-lived client assertions the server signs for the browser, which keeps the tokens. Set `SESSION_LIFETIME=20160` (14 days).
- **Public**: the metadata declares `none`, with no client key. Set `SESSION_LIFETIME=4320` (3 days).

What a PDS allows, as Bluesky's reference server (`@atproto/oauth-provider`) sets it, which other implementations may change:

- **Access tokens** last at most 60 minutes for every client, and never past the session's end. Both modes refresh them as they run out, so they do not decide how long anyone stays signed in.
- **A confidential client's** session lasts up to two years, as long as it is refreshed at least every three months.
- **A public client's** session ends two weeks after sign-in, however often it is refreshed.

Laravel's lifetime counts from the last request. In frontend mode a server session that ended is rebuilt from the browser's while the browser's session lives (see above), so a shorter lifetime costs a rebuild rather than a sign-in. Keep `SESSION_EXPIRE_ON_CLOSE` off, or every new browser window starts signed out.

## The atp guard

The `atp` guard answers one question per request: which DID is the viewer, and how did they prove it. It returns your `User` model, so `auth()`, Gates and policies work unchanged.

Add it next to your session guard in `config/auth.php`:

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'atp' => ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web'],
],
```

Your user model implements `HasAtpSession` from atp-client, returning its DID. The guard looks users up by that column on every request, so give it a unique index.

## Resolution order

1. **A bearer JWT** is verified as service auth with atp-client: the signature against the issuer's DID document, expiry, `ATP_SERVICE_AUTH_AUDIENCE` (a bare DID also matches a combined `did#serviceId`), and the route's NSID as `lxm`. A bearer that fails is a 401 and never falls back to the session. OAuth access tokens sent by mistake are refused.
2. **The session guard** supplies the user when there is no bearer.
3. **Neither** leaves the viewer anonymous.

## Protecting XRPC routes

```php
Route::get('/xrpc/com.example.getTimeline', GetTimeline::class)
    ->middleware('atp.viewer:required,com.example.getTimeline');

Route::get('/xrpc/com.example.getThread', GetThread::class)
    ->middleware('atp.viewer:optional,com.example.getThread');
```

The first argument is `required` or `optional`. The second is the NSID the route answers to, which a service auth token must be bound to: a route without one accepts the session only. Errors use the XRPC shape, such as `{"error": "AuthenticationRequired", "message": "..."}`. Plain `auth:atp` works too, for routes that only need a user.

## The viewer

```php
$viewer = $request->viewer();

$viewer->did;              // who is calling
$viewer->source;           // ViewerSource::Session or ViewerSource::ServiceAuth
$viewer->method;           // the NSID a service auth token was bound to
$viewer->user();           // the local user, if any
$viewer->isService();      // true for a did#serviceId issuer
$viewer->canActOnRepo();   // true when the server holds a session for this DID
$viewer->client();         // Atp::as($did), or null when the server holds no session
```

Write to the user's repo only when `canActOnRepo()` is true. In frontend mode the browser holds the tokens, writes to the repo itself, and the AppView changes only its own state.

## Provisioning and services

A verified DID with no local user gets one keyed by `did_column` when `provision` is `create`, the default. Users are only ever created from a verified credential, never from a DID in a request body. Set `provision` to `none` to leave the viewer without a user instead.

An issuer with a fragment, such as `did:web:feed.example#bsky_fg`, is a service rather than a person. It is only accepted when listed in `auth.trusted_services`, and is never provisioned.
