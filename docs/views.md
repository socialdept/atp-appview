# Hydrated views

Part of the [atp-appview docs](../README.md#documentation). Views with authors, embeds, counts and viewer state, fields kept in the index, profiles and images.

Default queries answer with records as stored. A view answers with what an app actually shows: the record, who wrote it, the records it points at, how many records point back at it, and what the viewer has done with it.

```php
use SocialDept\AtpAppview\Views\Parts\{Author, Count, Embed, ViewerRef};
use SocialDept\AtpAppview\Views\View;

class PostView extends View
{
    public function collection(): string
    {
        return 'com.example.post';
    }

    public function parts(): array
    {
        return [
            'author' => new Author(),
            'quote' => new Embed('quote.uri', PostView::class),
            'likeCount' => new Count('com.example.like', 'subject.uri'),
            'replyCount' => new Count('com.example.post', 'reply.parent.uri'),
            'viewer' => [
                'like' => new ViewerRef('com.example.like', 'subject.uri'),
            ],
        ];
    }
}
```

Serve it by mapping a query to it, with a lexicon for the method:

```php
// config/atp-appview.php
'xrpc' => [
    'views' => [
        'com.example.getPosts' => \App\Views\PostView::class,
    ],
],
```

It pages, filters by `did` or `actor` and looks up one `uri` exactly as a default query does, answering `{posts: [...], cursor}` or one view. The key is the collection's last segment, plural, or whatever `key()` returns. Auth is optional, so a viewer signed in by session or by service auth through their PDS gets `viewer` state. That needs the `atp` guard configured, as any method with auth does. A view used from a handler class instead goes in `atp-appview.views`, and `Hydrator::hydrate($view, $records, $viewerDid)` builds it.

| Part | Gives | Costs per page |
| --- | --- | --- |
| `Author` | `did`, `handle`, `displayName`, `avatar` from [profiles](#profiles) | one query per profile source, by primary key |
| `Embed($path, $view)` | the record a field points at, in that view's `summary()` | one query by primary key |
| `Count($collection, $path)` | how many visible records point here through that field | every count in the view is one read of the kept counts |
| `ViewerRef($collection, $path)` | the uri of the viewer's own record pointing here, or null | every viewer field shares one query, none without a viewer |

So a page costs the same number of queries whatever its size, and adding a count to a view adds no query. Paths are dot paths, and a field may hold one at-uri or a list. Override `summary()` to embed a short form, and `base()` to change the fields every view starts with.

For a page of your own, such as an Inertia infinite scroll or a JSON endpoint, `ViewPage` hydrates a paginator's records together and keeps the paginator intact, which a cursor paginator needs to read its next cursor. `present()` shapes each view first:

```php
use SocialDept\AtpAppview\Views\ViewPage;

Inertia::scroll(fn () => ViewPage::of(
    app(PostView::class),
    Record::query()->collection('com.example.post')->discoverable()->orderByDesc('sort_at')->orderByDesc('uri')->cursorPaginate(30),
    $request->user()?->did,
)->present(fn (array $view) => ['uri' => $view['uri'], 'author' => $view['author'], 'text' => $view['record']['text'] ?? '']));
```

`Record::$sort_at` keeps its microseconds as a string, so cursors over it never skip records that share a second.

## Threads

What makes a reply, where its parent lives and how replies sort are the app's. `ReplyTree` turns the parents into a tree of views. Find the thread's records with a [queryable field](#fields), such as every record sharing a root, and hand over each one's parent:

```php
use SocialDept\AtpAppview\Views\ReplyTree;

$records = Record::query()->where('collection', 'com.example.post')
    ->whereIn('id', $idsSharingTheRoot)->orderBy('sort_at')->get();

$parents = ReplyTree::parents($records, 'reply.parent.uri');   // uri => parent uri
$thread = app(ReplyTree::class)->build($anchorUri, $parents, $viewer, depth: 6, height: 80);
```

Each node has a `type`, its `uri`, and for a record its `view` and `replies`. The anchor also has its `parent`, which has its own, up to `height`. Replies keep the order of `$parents`, or pass `order` to compare two nodes.

The views come in one batch for the whole tree, so a deep thread takes no more queries than a shallow one. A reply the viewer cannot see is left out with everything under it. A parent the viewer cannot see stands as a placeholder, as Bluesky's threads have them: `blocked`, with its `author`, when the viewer blocks the author or is blocked by them, and `notFound` otherwise. Map the node types to your own lexicon's.

## Fields

Records can be filtered, sorted and counted by their own fields, declared per collection in `config/atp-appview/fields.php`:

```php
return [
    'com.example.post' => [
        'tags' => 'string[]',
        'langs' => 'string[]',
        'createdAt' => 'datetime',
        'parent' => ['type' => 'uri', 'path' => 'reply.parent.uri'],
    ],
];
```

A type is `string`, `uri`, `datetime`, `number` or `bool`, with `[]` for a list. `path` reaches a field by another name or nested. Strings match without regard to case unless `'case' => 'exact'`. Dates are read leniently, and a value that cannot be read is left out.

```php
use SocialDept\AtpAppview\Index\RecordQuery;

$page = RecordQuery::in('com.example.post')
    ->where('tags', 'php')
    ->where('langs', 'en')
    ->orderBy('createdAt')          // a date or number field, newest first
    ->after($cursor)
    ->limit(50)
    ->get();                        // ['records' => Collection, 'cursor' => ?string]
```

Without `orderBy()` records come newest first by `sort_at`. Several filters are all required, and the one with the fewest matches drives the query. `by($did)` narrows to one repo. Sorting by a field leaves out records without a value for it.

A view query maps request parameters to fields, which its lexicon declares:

```php
'xrpc' => [
    'views' => [
        'com.example.getPosts' => [
            'view' => PostView::class,
            'filters' => ['tag' => 'tags', 'lang' => 'langs'],   // ?tag=php&lang=en
            'sorts' => ['created' => 'createdAt'],              // ?sort=created
        ],
    ],
],
```

Counts are kept as records change, so the most common values and suggestions read them rather than count:

```php
$values = app(\SocialDept\AtpAppview\Index\RecordValues::class);

$values->top('com.example.post', 'tags', 20);          // [['value' => 'php', 'total' => 312], ...]
$values->suggest('com.example.post', 'tags', 'lar');   // tags starting with "lar", most used first
```

For a window such as "trending this week", pass `since`. That counts the values of records sorted since then, leaving out records held back from discovery, so it reads `atp_record_values` instead of the kept totals. `authors()` names the latest few accounts holding each value:

```php
$week = now()->subWeek();

$values->top('com.example.post', 'tags', 5, since: $week);            // the week's top 5
$values->authors('com.example.post', 'tags', ['php', 'rust'], 3, $week); // ['php' => [did, did, did], 'rust' => [...]]
```

### How fields are stored

Every value is a row in `atp_record_values`: a 2-byte id for the collection and field, the record's 8-byte id, the value as an 8-byte hash, the date or number when there is one, and the record's `sort_at` and whether its account is hidden. So a filtered page reads one index range and never joins `atp_records`. Matching is exact, which is what filters need: there is no alphabetical sort or substring match on strings. Search comes from a search index, and anything else can be built from the records themselves.

References are fields too: a `uri` value is the record it points at. The `Count` and `ViewerRef` parts of a view name references, which are kept without being declared. `atp_value_counts` holds how many visible records have each value, moved as values are written, hidden, shown and dropped, in atomic `+n` updates that concurrent batches cannot lose.

A batch rewrites only the values of records it changed, from the records already in memory, and writes only what differs, so a resync of unchanged records writes nothing. A tombstoned record has no values, and a hidden account's are marked hidden.

`appview:fields` builds a field declared later for records already indexed, rebuilds one whose type or case changed, and drops a removed one. `--recount` counts every value again, to repair counts that drifted. Schedule it:

```php
Schedule::command('appview:fields')->everyFiveMinutes()->withoutOverlapping();
```

On Postgres with 290,000 records, 1.28 million values take 300 MB with their indexes. A page filtered by a value reads in under a millisecond, a sort by a date in about one, two filters together in a few. A common value paired with a sort is read and sorted, about 50 ms for 17,000 matches.

## Profiles

`Profiles` turns accounts into profiles for `Author` and anything else that shows people. Sources are tried in order, and the first with a record for the account is its profile, empty fields and all, so one app's profile never borrows another's bio. Set `profiles.merge` to `field` to fill each standard field from the first source that has it instead.

```php
// config/atp-appview.php
'profiles' => [
    'sources' => [
        ['collection' => 'com.example.actor.profile', 'fields' => ['pronouns']],
        ['collection' => 'app.bsky.actor.profile'],
    ],
],
```

Every profile has `displayName`, `description`, `avatar` and `banner`. A source's extra `fields` are passed through, and only when that source is the one used. A lexicon that names the standard fields differently maps them with `aliases`, and a field can be computed, fluently at boot:

```php
Profiles::source('com.example.actor.profile')
    ->aliases(['displayName' => 'name'])
    ->map('tagline', fn (array $record) => $record['bio'] ?? null);
```

An account with no profile anywhere is its `did` and `handle`, with `displayName` null for clients to fall back to the handle. A deactivated account shows only those two.

Profiles come from the index for collections it ingests. List them as [companion collections](ingest.md#companion-collections), so the profiles of followed repos are indexed without following every account that has one. An account the index does not know, or whose profile collection is still being backfilled, is fetched from Slingshot instead, every account on a page at once, and cached for an hour, a missing profile included.

`Profiles::forget($did)` drops an account's fetched profiles, so the next read fetches them again. `IdentityRefresh::refresh($did)` goes further: it reads the account's DID document again, past every cache, stores its PDS, and its handle when the handle resolves back to the DID, then forgets the fetched profile. It returns what changed. Use it when an account shows an old handle, PDS or profile, which otherwise wait for Tap's next identity event or a lookup's TTL.

## Images

Blobs such as avatars are served through [img.infra.coop](https://img.infra.coop), a free image CDN for atproto that resizes and caches them, so browsers are never handed full-size originals. Each use maps to one of its [presets](https://img.infra.coop/presets): `avatar-sm` for an embedded author or icon, `avatar` and `banner` on a profile. The public instance asks for a small credit linking to [infra.coop](https://infra.coop).

```dotenv
ATP_APPVIEW_IMAGES=infra                         # or pds, for the original from the account's PDS
ATP_APPVIEW_IMAGES_URL=https://img.infra.coop    # or a private deployment
```

`Blobs::url($blob, $did, 'icon')` builds one in a view's `summary()`.
