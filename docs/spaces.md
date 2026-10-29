# Spaces

Part of the [atp-appview docs](../README.md#documentation). A copy of the permissioned spaces the app follows, synced from each member's own PDS and read only by viewers the space's authority lets in.

Spaces is a [feature](features.md), `Features::spaces()`, off unless listed. It builds on [atp-spaces](https://github.com/socialdept/atp-spaces), which reads spaces, checks what it reads, and receives the notifications a space's authority sends.

A space is permissioned data. Its records live in a repo per member on each member's own PDS, nothing relays them, and reading them takes a space credential from the space's authority. The AppView keeps its own copy of the spaces the app follows, in tables of their own:

| Table | Holds |
| --- | --- |
| `atp_spaces` | One row per followed space: the authority's `spaceRev` cursor, whose stored credential syncs it, when its notification registration lapses, when it last synced, when it went stale, and its last error |
| `atp_space_repos` | One row per member repo: the revision synced to and the running set hash at that revision |
| `atp_space_records` | The records, with the same columns as `atp_records` plus `space`. The `uri` is the space record URI, `at://{authority}/space/{type}/{skey}/{did}/{collection}/{rkey}` |
| `atp_space_grants` | Who may read each space, and until when |

Space records are never written to `atp_records`. Nothing that reads the public index (queries, views, search, notifications, the graph, feeds, standing) can see them, and a feature added later cannot either.

## Following a space

The AppView never discovers spaces on its own. The app follows one when it starts caring about it, such as when a user creates, joins or first opens it, with a credential for that user:

```php
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpSpaces\Facades\Spaces;

$credential = Spaces::credential($space, $client);

app(SpaceSync::class)->follow($credential);
```

Following stores the credential, grants that account the space, and queues the first sync. One credential syncs the whole space, the one stored for the account that followed or [entered](#entering-a-space) it last. When atp-spaces lists space types in `atp-spaces.types`, only spaces of those types can be followed.

`unfollow($space)` withdraws the notification registration and drops the copy and its grants.

## Entering a space

A viewer may read a space's records while they hold a grant for it. A grant comes from the viewer getting a credential through the AppView: if the space's authority issues one, they are in until it expires, about ten minutes. That reuses the authority's own decision, whatever its policy (a member list, a managing app, or public), instead of copying who belongs.

So the app enters the space for the viewer whenever they use it, and the grant follows them:

```php
use SocialDept\AtpAppview\Spaces\SpaceGrants;

// Backend mode: the server holds the viewer's session.
$expiresAt = app(SpaceGrants::class)->enter($space, $did);
```

`enter()` takes a DID or the request's `Viewer`, and reuses a credential stored for them while it is still good. Without a server-side session for the viewer it throws, since in frontend mode only the browser can get a delegation token.

In frontend mode the browser asks its own PDS for a delegation token for the space (`com.atproto.space.getDelegationToken`) and hands it to the procedure named in `spaces.xrpc.enter`:

```http
POST /xrpc/com.example.space.enter
{"space": "at://did:plc:abc/space/com.example.forum/main", "delegationToken": "eyJ..."}

{"space": "at://did:plc:abc/space/com.example.forum/main", "expiresAt": "2026-10-29T12:10:00Z"}
```

It takes a Laravel session or a service auth token, as any XRPC method does, and the token must name that viewer. In PHP the same is `enterFromDelegation($space, $did, $token)`.

Either way:

- Only a followed space that was not deleted can be entered.
- The grant is recorded or renewed to expire with the credential, and the space syncs with this viewer's credential from now on. A stale space resumes, a sync queued to catch it up.
- When the authority refuses the viewer (`UserNotAuthorized`, `AppNotAuthorized` or `CredentialRevoked`), any grant they held is dropped and the refusal rethrown. `SpaceDeleted` drops the whole space. A bad token or a network failure leaves the grant alone.

| The procedure answers | When |
| --- | --- |
| `400 InvalidRequest` | The input is not a space URI and a token |
| `400 InvalidDelegationToken` | The token is unusable, for another space, or names another account |
| `401 AuthenticationRequired` | No viewer, or a service calling as itself |
| `403` with the authority's error | The authority refused the viewer |
| `404 SpaceNotFound` | The space is not followed, or was deleted |
| `404 SpaceDeleted` | The authority says the space is gone |
| `502 UpstreamFailure` | The authority could not be reached or answered something unusable |

Grants expire on their own: an expired one allows nothing, and `appview:spaces:sync` deletes them. A viewer who stops using the app drops out within a credential's lifetime, since nothing renews their grant in the background.

## Reading space records

Space records are read only through `SpaceRecordQuery`, which asks for a space and a viewer:

```php
use SocialDept\AtpAppview\Spaces\SpaceRecordQuery;

['records' => $records, 'cursor' => $cursor] = SpaceRecordQuery::in($space)
    ->visibleTo($did)
    ->collection('com.example.post')
    ->after($cursor)
    ->limit(50)
    ->get();
```

Records come back as `Models\SpaceRecord`, newest first by `sort_at`, in keyset pages, without tombstones. `by($did)` narrows to one member's repo. Without `visibleTo()`, or for a viewer who may not read the space, it finds nothing.

Moderation applies as it does to public records: nothing by accounts the viewer blocks, is blocked by or muted, or by accounts that are deactivated, taken down or suspended. With the moderation feature, nothing by banned accounts or that a label hides, on the record or its account, and what needs review only when the query is for one repo. Record filters such as muted words read the public value index, which holds no space records, so they do not apply.

`RecordQuery`, `Record` and everything else that reads `atp_records` never see a space record.

### Answering who may read

`SpaceRecordQuery` asks `Spaces\Contracts\SpaceAccess`, which by default is `SpaceGrants` and its table. An app that already knows who may read, such as the space's managing app, binds its own:

```php
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;

$this->app->bind(SpaceAccess::class, MySpaceAccess::class);

class MySpaceAccess implements SpaceAccess
{
    public function allows(SpaceRef $space, ?string $viewer): bool
    {
        return $viewer !== null && Membership::where('space', (string) $space)->where('did', $viewer)->exists();
    }
}
```

The viewer is null for someone signed out, which the default never allows.

## How a space syncs

A sync pages through the space's repos with `listRepos` from the stored `spaceRev`, and syncs each repo whose revision moved:

1. Read the repo's operation log with `listRepoOps` from the revision last synced.
2. Fold every op into the set hash kept for the repo and compare it with the repo's commit.
3. When they match, write the records and keep the new revision and hash.
4. When they do not, when the log no longer reaches back that far, or when an op came without its record, read the whole repo with `getRepo` and replace the copy, tombstoning what it no longer holds.

Records go through the configured ingest pipes, the blocklist, repairs and hooks, as public records do, and then only into `atp_space_records`. A record a hook rejects is not stored, but still counts toward the hash, since the hash describes the repo.

The sync reads with the credential stored in atp-spaces' credential store. When there is none still good, the space is marked stale and waits. The next sync after a credential is stored catches it up from its cursor.

## Notifications

While the feature is on, atp-spaces serves the routes a space's authority notifies, `com.atproto.space.notifyWrite` and `com.atproto.space.notifySpaceDeleted`, and each sync registers for them with `registerNotify`, renewing the registration once it has less than `notify.renew_before` seconds left. The routes check the caller with service auth, so set `ATP_SERVICE_AUTH_AUDIENCE` to the identifier the authority delivers to, or name one in `notify.service`.

| Notification | Does |
| --- | --- |
| A write that follows the stored `spaceRev` | Queues `Spaces\SyncSpaceRepo` for that repo |
| A write after a gap | Queues `Spaces\SyncSpace` for the whole space, since notifications were missed |
| The space was deleted | Tombstones its records, forgets its repos, drops its grants and marks it deleted |

Notifications for spaces the app does not follow are ignored. Delivery is best effort, so `appview:spaces:sync` is the backstop.

## Syncing on a schedule

```bash
php artisan appview:spaces:sync                 # every followed space, here
php artisan appview:spaces:sync --queue         # every followed space, queued
php artisan appview:spaces:sync at://did:plc:abc/space/com.example.forum/main
```

`Features::schedule()` runs it every ten minutes, queued. It also renews registrations before they lapse, and deletes expired grants.

## Record events

Records arriving from a repo's operation log are announced once the sync is committed:

| Event | When | Carries |
| --- | --- | --- |
| `Spaces\Events\SpaceRecordIndexed` | a record was created or updated | `space`, `uri`, `did`, `collection`, `rkey`, `action`, `record` |
| `Spaces\Events\SpaceRecordRemoved` | a record was deleted from its repo | `space`, `uri`, `did`, `collection`, `rkey`, and the `record` it held |

They are never dispatched as `RecordIndexed` or `RecordRemoved`, so realtime's public channels never hear of a space record. A repo replaced from its archive is not announced, as a backfill is not.

## Configuration

```php
// config/atp-appview/spaces.php
'notify' => [
    'enabled' => true,
    'service' => null,          // ATP_SERVICE_AUTH_AUDIENCE when null
    'renew_before' => 21600,
],
'page_size' => 100,
'xrpc' => [
    'enter' => null,            // such as 'com.example.space.enter'
],
```
