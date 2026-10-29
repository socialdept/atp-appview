# Spaces

Part of the [atp-appview docs](../README.md#documentation). A copy of the permissioned spaces the app follows, synced from each member's own PDS.

Spaces is a [feature](features.md), `Features::spaces()`, off unless listed. It builds on [atp-spaces](https://github.com/socialdept/atp-spaces), which reads spaces, checks what it reads, and receives the notifications a space's authority sends.

A space is permissioned data. Its records live in a repo per member on each member's own PDS, nothing relays them, and reading them takes a space credential from the space's authority. The AppView keeps its own copy of the spaces the app follows, in tables of their own:

| Table | Holds |
| --- | --- |
| `atp_spaces` | One row per followed space: the authority's `spaceRev` cursor, whose stored credential syncs it, when its notification registration lapses, when it last synced, when it went stale, and its last error |
| `atp_space_repos` | One row per member repo: the revision synced to and the running set hash at that revision |
| `atp_space_records` | The records, with the same columns as `atp_records` plus `space`. The `uri` is the space record URI, `at://{authority}/space/{type}/{skey}/{did}/{collection}/{rkey}` |

Space records are never written to `atp_records`. Nothing that reads the public index (queries, views, search, notifications, the graph, feeds, standing) can see them, and a feature added later cannot either.

## Following a space

The AppView never discovers spaces on its own. The app follows one when it starts caring about it, such as when a user creates, joins or first opens it, with a credential for that user:

```php
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpSpaces\Facades\Spaces;

$credential = Spaces::credential($space, $client);

app(SpaceSync::class)->follow($credential);
```

Following stores the credential and queues the first sync. One credential syncs the whole space, the one stored for the account that followed it last. When atp-spaces lists space types in `atp-spaces.types`, only spaces of those types can be followed.

`unfollow($space)` withdraws the notification registration and drops the copy.

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
| The space was deleted | Tombstones its records, forgets its repos and marks it deleted |

Notifications for spaces the app does not follow are ignored. Delivery is best effort, so `appview:spaces:sync` is the backstop.

## Syncing on a schedule

```bash
php artisan appview:spaces:sync                 # every followed space, here
php artisan appview:spaces:sync --queue         # every followed space, queued
php artisan appview:spaces:sync at://did:plc:abc/space/com.example.forum/main
```

`Features::schedule()` runs it every ten minutes, queued. It also renews registrations before they lapse.

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
```
