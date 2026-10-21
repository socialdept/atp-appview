# Moderation

Part of the [atp-appview docs](../README.md#documentation). Labels from labelers through Tack, policies, self-labels and account actions.

## Labels

Labels and account actions are the moderation [feature](features.md#features), `Features::moderation()`, on by default. Labels come from labelers, such as Bluesky's moderation service, through [Tack](https://github.com/socialdept/tack). Tack follows the labelers, verifies every label's signature, fetches the labels an account already has when the app starts tracking it, and delivers labels about the accounts the index knows, the same ways Tap delivers records.

```dotenv
ATP_APPVIEW_TACK_URL=http://localhost:2490
ATP_APPVIEW_TACK_PASSWORD=...                # Tack's TACK_ADMIN_PASSWORD
```

Point Tack's `TACK_WEBHOOK_URL` at `/atp-appview/tack` (`labels.webhook.path`), or leave it unset and run `appview:labels:consume`, which reads Tack's websocket the way `appview:consume` reads Tap's. Labels go through the same ingest as records, failed deliveries included, written by `LabelIndexer`, which is in the default `ingest.handlers`.

Schedule the sync. It registers the accounts the index knows with Tack, with the URIs of their records for labelers that only answer for exact URIs, and drops expired labels:

```php
Schedule::command('appview:labels:sync')->everyMinute()->withoutOverlapping();
```

Authors' own labels on their records (`labels.values`, self-labels) count too, with the author as their source. Turn them off with `labels.self_labels`. Records indexed before self-labels were kept, or while they were off, get theirs from `php artisan appview:labels:self`, which is safe to run again.

**What a label does** is an effect, decided per labeler, value and target. A label on a record is about content, and one on an account, or its profile record, is about the account:

| Effect | Content | Account |
| --- | --- | --- |
| `ignore` | Nothing | Nothing |
| `badge` | Shown, nothing hidden | Shown on the account |
| `warn` | Shown behind a warning | The profile behind a warning |
| `blur` | Media, or the whole record, blurred | Avatar and banner blurred |
| `hide` | Left out of lists and views | Everything the account wrote left out |
| `review` | Needs review: left out of discovery, shown where asked for directly | The same, for everything it wrote |
| `ban` | | The account is banned off the app |

The first of these decides:

1. An operator's policy, in `atp_label_policies`, for the labeler, then for any labeler (`*`).
2. `labels.policies` in config, the same way.
3. The global values every labeler may use: `!hide`, `!takedown` and `!suspend` hide, `!warn` warns, `!no-promote` needs review.
4. The labeler's declaration of the value: its `defaultSetting`, what it blurs, its severity.
5. Defaults for the values Bluesky uses without labelers defining them. On content, `porn` hides, and `sexual`, `nudity`, `graphic-media` and `gore` blur media. On an account they blur its avatar and banner and leave what it wrote alone, as Bluesky does. `needs-review`, Bluesky's label for an account awaiting review, needs review.

Anything else is ignored: still listed on what it labels, doing nothing.

`!no-unauthenticated` is a request rather than a judgement: an author self-labels their profile with it to ask apps not to show them to signed-out viewers. Decisions carry it as `noUnauthenticated`, from the content or its author, and `decide($record, $viewer)`, `decideMany($records, $viewer)` and the `Moderation` view part give a signed-out viewer `hide` for it. Nothing is hidden from queries: it depends on who is looking.

```php
// config/atp-appview/labels.php
'policies' => [
    '*' => ['spam' => ['account' => 'review']],
    'did:plc:ar7c4by46qjdydhdevvrndac' => ['sexual' => ['content' => 'hide']],
],
```

Content a label hides carries `moderated_at`, kept apart from `hidden_at`, which follows the account's status, so one never undoes the other. Queries and counts leave it out through the same indexes. Content that needs review carries `limited_at`, and discovery leaves it out. `review` and `ban` on accounts are applied as [account actions](#account-actions).

**In views**, `Labels` gives each record its labels in the shape atproto views carry them, `Labels(author: true)` its author's, and `Moderation` what to do with it:

```php
'labels' => new Labels(),
'moderation' => new Moderation(),
```

```json
"moderation": {"blur": "media", "warn": [{"src": "did:plc:...", "val": "graphic-media"}], "author": {"review": true}}
```

It is always a JSON object, `{}` when there is nothing to say. In PHP it is an `ArrayObject`, read like an array (`$view['moderation']['blur'] ?? null`). Cast it with `(array)` before using array functions or operators on it.

The browser library has the part's type and helpers for showing it, so a moderation gate does not redefine them. A content warning covers what was written, and an author's account labels never do:

```ts
import { authorLabels, badges, hiddenFromGuests, labelName, warnings } from '@socialdept/atp-appview';
import type { Moderation } from '@socialdept/atp-appview';

warnings(view.moderation);           // the content's own warnings, which cover it
badges(view.moderation);             // its badges
authorLabels(view.moderation);       // the author's account labels, as notes
hiddenFromGuests(view.moderation);   // hidden only because the author asked to be, so offer a sign-in
labelName('graphic-media');          // "graphic media"
```

`authorName()` and `authorHandle()` do the same for the `Author` part: the display name, else `@handle`, else `@handle.invalid` (`INVALID_HANDLE`), as Bluesky shows an account without a valid handle. A DID is never shown as a name.

`Labels` is the API for everything else:

```php
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Labels\Target;

$labels = app(Labels::class);

$labels->decide($record);                              // a Decision: hide, review, ban, warnings, blur, badges, author
$labels->account('did:plc:...');                       // the same for an account
$labels->on($uri);                                     // the live labels on a uri or DID
$labels->set('*', 'spam', Target::ACCOUNT, Effect::Review);   // an operator's policy, applied at once
$labels->clear('*', 'spam', Target::ACCOUNT);
$labels->follow('did:plc:...');                        // ask Tack to follow a labeler
$labels->labelers();                                   // the labelers, with their declarations
```

## Account actions

What an operator, or a label, does to an account. Every action is a row in `atp_account_actions`, kept when it is lifted, with who did it and why, so the table is also the record of moderation.

| Action | What happens |
| --- | --- |
| Ban | The account cannot sign in, and its sessions and tokens stop working at once. Everything it wrote is hidden, and nothing new is indexed for it. Its records are pruned `moderation.prune_banned_after_days` (365) after the ban, unless the pruning is cancelled. Unbanning shows them again and asks Tap to send what it missed |
| Needs review | Everything it wrote is left out of discovery, and still shown on its profile and where asked for directly. It signs in and posts as usual |
| Takedown | The account is taken down on a PDS the operator runs, through its admin API. Separate from a ban, which only affects the app |

```php
use SocialDept\AtpAppview\Moderation\AccountActions;

$actions = app(AccountActions::class);

$actions->ban($did, reason: 'spam', actor: $operatorDid);
$actions->unban($did, actor: $operatorDid);
$actions->review($did);
$actions->unreview($did);
$actions->takedown($did, reason: 'illegal content');   // on a PDS in pds.hosts
$actions->restore($did);
$actions->cancelPrune($did);                           // keep a banned account's records
$actions->banned($did);
$actions->history($did);
```

**From labels.** An account label whose effect is `ban` or `review` applies that action itself, recorded with the label as its source, and lifts it when the label is withdrawn or expires. Manual actions are never lifted by a label, so a ban an operator made outlasts any label. Unbanning lifts every ban, a label's included, though a label still in force applies its ban again the next time the account's labels are read.

**Discovery.** `RecordQuery` leaves records that need review out of anything across accounts and keeps them in `by($did)`. Default XRPC lists do the same without a repo. Use `Record::discoverable()` for your own feeds and search.

**PDS hosts.** The PDSes the operator runs go in `config/atp-appview/pds.php`, each with a driver. `admin` speaks a stock PDS's admin API with its admin password. Another host implements `SocialDept\AtpAppview\Pds\Contracts\PdsDriver` (`takedown`, `restore`, `takenDown`).

```dotenv
ATP_APPVIEW_PDS_URL=https://pds.example.com
ATP_APPVIEW_PDS_ADMIN_PASSWORD=...
```

Schedule the pruning:

```php
Schedule::command('appview:moderation:prune')->daily();
```

**Events**, each dispatched once its change is committed, for what effects do not do, such as telling someone:

| Event | When |
| --- | --- |
| `Labels\Events\LabelApplied` | A label now applies, with its target and effect |
| `Labels\Events\LabelWithdrawn` | A label was withdrawn, or removed with its labeler |
| `Moderation\Events\AccountActioned` | An account was banned, put under review or taken down, or one was lifted |

```php
Event::listen(function (AccountActioned $event) {
    if ($event->action->action === 'ban' && ! $event->lifted) {
        // tell the moderators
    }
});
```
