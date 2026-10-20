# XRPC

Part of the [atp-appview docs](../README.md#documentation). Default queries and procedures, custom handlers, calling methods, and writing records.

Every class carrying the `#[Xrpc]` attribute becomes a route at `/xrpc/{nsid}`. Whether it is a query (GET) or a procedure (POST) comes from its lexicon, loaded through atp-schema from `resources/lexicons`.

```php
namespace App\Http\Xrpc;

use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

#[Xrpc('com.example.getTimeline', auth: Auth::Required)]
class GetTimeline
{
    public function __invoke(XrpcRequest $request): array
    {
        $limit = $request->param('limit', 50);   // an int, as the lexicon declares
        $viewer = $request->viewer();

        return ['feed' => []];
    }
}
```

Handlers live in `app/Http/Xrpc` beside your controllers, since they handle incoming requests the same way, and are discovered there automatically. `atp-appview.xrpc.handlers` registers others, and `php artisan appview:routes` lists them.

- **Auth.** `Auth::Required` refuses anonymous requests, `Auth::Optional` (the default) resolves the viewer when there is one, and `Auth::None` skips the guard. Required and optional routes bind service auth tokens to the method's NSID.
- **Parameters** are read from the raw query string and cast to the lexicon's types, so `limit=25` arrives as `25` and a repeated `uris=a&uris=b` as an array. They are then validated against the lexicon's constraints.
- **Input.** A procedure's JSON body is validated against its lexicon's input schema and available as `$request->input()`.
- **Responses.** Return an array, `Arrayable`, `JsonSerializable`, `Responsable` or `Response`, or null for an empty 200. Throw `XrpcException` for an XRPC error such as `{"error": "NotFound", "message": "..."}`. Invalid parameters or input answer `InvalidRequest`.
- **Injection.** Handlers are resolved from the container and can type-hint `XrpcRequest`, `Viewer`, `Request` or any service.
- **Unknown methods** answer `MethodNotImplemented`, and a known method called with the wrong HTTP method answers `InvalidRequest` naming the right one. Your own routes under `/xrpc` still take precedence.
- **Lexicons load lazily.** Each route accepts GET and POST, and the controller refuses the verb the lexicon does not allow, so registering routes at boot reads no lexicon and touches no cache. A method's lexicon loads the first time it is called. `php artisan appview:routes` loads them all, and is where a misconfigured mapping shows up before traffic does.

## Default queries

A query that only lists or fetches one collection needs no handler class. Map it to the collection in `atp-appview.xrpc.collections`, give it a lexicon, and it is served straight from the index:

```php
'collections' => [
    'com.example.listThings' => 'com.example.thing',
],
```

The responses follow `com.atproto.repo.getRecord` and `listRecords`:

- **With `uri`**, it answers `{"uri", "cid", "value"}` for that record, or `RecordNotFound` when the index does not hold it in this collection.
- **Otherwise** it answers `{"records": [...], "cursor"}`, newest first by `sort_at`. `limit`, `cursor` and `reverse` page through the list, and `did` or `actor` (a handle the index knows) narrow it to one repo.

Declare those parameters in the lexicon, whose `limit` default and maximum apply. The cursor is opaque and keyset based, so pages never skip or repeat a record while new ones arrive. Default queries are public (`Auth::None`), and a handler class for the same method takes precedence. Procedures still need a handler class.

## Reaching the AppView through a PDS

In frontend mode the browser calls the AppView through its own PDS, naming it in the `atproto-proxy` header. The PDS resolves the AppView's DID to find where to send the request, then signs a service auth token for it. For a `did:web`, set the endpoint and the package serves the DID document:

```dotenv
ATP_SERVICE_AUTH_AUDIENCE="did:web:example.com#atproto_appview"
ATP_APPVIEW_SERVICE_ENDPOINT=https://example.com
```

```
GET /.well-known/did.json
→ {"id": "did:web:example.com", "service": [{"id": "#atproto_appview", "type": "AtprotoAppView", "serviceEndpoint": "https://example.com"}]}
```

```ts
await pdsFetch(did, '/xrpc/com.example.getThings', {
    headers: { 'atproto-proxy': 'did:web:example.com#atproto_appview' },
});
```

The endpoint must be reachable from the internet, since the PDS makes the call. Only `/.well-known/did.json` and the XRPC routes need to be.

## Calling methods

One call shape for every XRPC method, whoever answers it. In PHP, this AppView's own methods are answered in-process, through the same handler, auth and lexicon validation as over HTTP, with no request made. That suits a page's first render: the props are what the browser gets from the same method later.

```php
use SocialDept\AtpAppview\Facades\Xrpc;

Xrpc::as($request->viewer())->query('com.example.notification.listNotifications', ['limit' => 30]);
Xrpc::query('com.example.getThings');                                  // anonymous
Xrpc::as($did)->service('did:web:api.bsky.app#bsky_appview')->query('app.bsky.feed.getTimeline');
```

`as()` takes a `Viewer`, a user or a DID. Another service is called through the account's PDS with `atproto-proxy`, which needs the session the server holds for them, so it works in backend mode. In frontend mode only the browser holds tokens, and the call answers `SessionRequired`: make it from the browser. Without anyone to call as, another service is called directly, at the endpoint its DID document gives. Errors are thrown as `XrpcException` with the method's error name, whoever answered.

The browser library has the same shape, and picks how to authenticate:

```ts
import { xrpc, XrpcError } from '@socialdept/atp-appview';

await xrpc.query('com.example.notification.getUnreadCount');
await xrpc.procedure('com.example.notification.updateSeen', {});
await xrpc.query('app.bsky.feed.getTimeline', {}, { service: 'did:web:api.bsky.app#bsky_appview' });
```

| Calling | Signed in | Sent |
| --- | --- | --- |
| This AppView | with a server session | same origin, with the cookie and CSRF header |
| This AppView | browser tokens only, or the cookie ran out | through the account's PDS, proxied to `audience` |
| Another service | yes | through the account's PDS, proxied to that service |
| Anything | no | directly, unauthenticated |

It reads `audience` and `xrpcPrefix` from `FrontendAuth::browserConfig()`, passed to `configureAtproto()`. A call through the PDS can throw `SessionExpiredError`: wrap one the user started in `withSession()` to offer reconnecting, and leave background calls, such as polling, bare.

## Writing records

**Frontend mode.** The browser writes to its own PDS, and Tap delivers the record within seconds. To show the author their record straight away, turn on the sync procedure and have the browser call it, through its PDS, right after writing:

```php
// config/atp-appview.php
'xrpc' => [
    'sync_record' => 'com.example.syncRecord',
],
```

```
POST /xrpc/com.example.syncRecord   {"uri": "at://did:plc:alice/com.example.thing/3abc"}
→ {"uri": "at://did:plc:alice/com.example.thing/3abc", "indexed": true}
```

In the browser, `createRecord()` and `deleteRecord()` do both steps: write to the signed-in account's PDS, then call the sync procedure through the [xrpc helper](#calling-methods), same origin or through the PDS as it can. Each step runs in `withSession()` on its own, so a dead session offers to reconnect and only the step that never happened runs again. Errors are `RecordWriteError` with the PDS's message and status:

```ts
import { createRecord, deleteRecord } from '@socialdept/atp-appview';

const { uri } = await createRecord('com.example.thing', { text: 'hi', createdAt: new Date().toISOString() });
await deleteRecord(uri);
```

They read `syncRecord` and `proxy` from `FrontendAuth::browserConfig()`, passed to `configureAtproto()`. `proxy` is whether a PDS can reach this AppView, which needs a published service DID. Without one, calls stay same origin.

It fetches the record from the caller's own PDS and indexes it, or tombstones it if it is gone, so deletes show up as quickly. It takes only the caller's own records, only in `ingest.collections`, and never the record body from the request. The revision is read before the record, so Tap's later delivery can only replace it. The record passes through the same pipeline as Tap's deliveries, repairs and hooks included, so a record a block rule or a hook keeps out answers `indexed: false`.

**Backend mode.** Default procedures write one collection to the viewer's repo through the session the server holds, then index the record before answering:

```php
'xrpc' => [
    'procedures' => [
        'com.example.createThing' => ['collection' => 'com.example.thing', 'action' => 'create'],
        'com.example.putThing' => ['collection' => 'com.example.thing', 'action' => 'put'],
        'com.example.deleteThing' => ['collection' => 'com.example.thing', 'action' => 'delete'],
    ],
],
```

| Action | Input | Answers |
|---|---|---|
| `create` | `{record, rkey?}` | `{uri, cid}` |
| `put` | `{rkey, record, swapRecord?}` | `{uri, cid}` |
| `delete` | `{rkey, swapRecord?}` | nothing |

- The viewer is required, and must be one the server holds a session for. Others get `BackendSessionRequired` (403), telling them to write to their PDS.
- The record is given the collection's `$type`, and checked against the collection's lexicon when one is loaded, before anything reaches the PDS. The PDS is asked not to validate, since it refuses lexicons it does not know.
- The record is indexed with the commit's revision, so the author sees it at once and Tap's later delivery changes nothing.
- The PDS's own errors, such as `InvalidSwap`, come back as they were. Repos a block rule covers get `RepoBlocked` (403).
- A lexicon for the procedure is optional. If one exists, it must be a procedure.

## Middleware

Add `'web'` to `atp-appview.xrpc.middleware` so session viewers resolve in backend mode. A handler without a lexicon declares `type: 'query'` or `type: 'procedure'` on the attribute.

**CSRF.** The web group's CSRF check would refuse every procedure a PDS calls on a user's behalf, since those carry a service auth bearer token and no CSRF token. So XRPC routes take that check off and run their own: requests with a bearer token, or with no session cookie, pass, since nothing the browser attaches on its own is involved. A request riding on a session cookie still runs your application's own CSRF middleware, with its exceptions, and a failure answers `{"error": "CsrfTokenMismatch"}` with status 419. Browser code calling procedures with the session sends the token as usual, as Inertia and axios do.
