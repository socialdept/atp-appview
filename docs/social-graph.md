# Social graph

Part of the [atp-appview docs](../README.md#documentation). Follows and blocks from the collections you name, private mutes of accounts and words, and where they apply.

Who follows and blocks whom comes from records, in collections the app names: usually its own lexicon, or another app's. Mutes are private, so the app keeps them itself. It is a [feature](features.md#features), `Features::graph()`, on by default. Left out, nobody follows, blocks or mutes anyone, and `mute()` refuses.

```php
// config/atp-appview/graph.php
'follows' => ['collection' => 'com.example.graph.follow', 'path' => 'subject'],
'blocks' => ['collection' => 'com.example.graph.block', 'path' => 'subject'],
```

The fields are kept in the index like references, so run `appview:fields` after setting them. An app with no graph of its own can name Bluesky's, `app.bsky.graph.follow` and `app.bsky.graph.block`. Either way the records must be indexed: ingested, a [companion collection](ingest.md#companion-collections), or reconciled for your own users with `Reconciler::reconcile($did, collections: [...])`.

```php
use SocialDept\AtpAppview\Graph\Graph;

$graph = app(Graph::class);

$graph->follows($alice, $bob);        // does Alice follow Bob
$graph->following($alice);            // who Alice follows
$graph->followers($bob);              // who follows Bob, newest first
$graph->blocking($alice);             // who Alice blocks
$graph->blockedBy($alice);            // who blocks Alice
$graph->mute($alice, $bob);           // and unmute(), muted()
$graph->hidden($alice);               // blocked either way, or muted
```

Blocks apply both ways and mutes only to the muter, wherever records are listed for a viewer:

| Where | How |
| --- | --- |
| Your own queries | `Record::query()->visibleTo($viewer)`, and `->followedBy($viewer)` for a following feed |
| Filtered queries | `RecordQuery::in($collection)->visibleTo($viewer)` |
| Built-in XRPC lists | The signed-in viewer, automatically |
| Notifications | Nothing from hidden accounts or about records holding a muted word. Unblocking or unmuting brings them back |

## Muted words

A viewer can mute words, phrases, tags or emoji, in any collection. The app names where each collection's words are, grouped into sources a muted entry can be limited to:

```php
// config/atp-appview/graph.php
'muted_words' => [
    'com.example.post' => [
        'text' => ['text'],
        'tags' => ['tags', 'facets.*.features.*.tag'],
    ],
],
```

Each path is kept in the index as words: split on anything that is not a letter, number or emoji, lowercased, an emoji with its skin tone or joiners kept whole. A path can be a string or a list of strings, and `*` walks into lists, so tags in rich text facets are found the same way as a `tags` field. Run `appview:fields` after changing it, which builds the words of records already indexed.

```php
$id = $graph->muteWord($alice, 'spoilers');                    // every source
$graph->muteWord($alice, '#spoilers', ['tags']);               // tags only
$graph->muteWord($alice, 'violent protest', until: now()->addWeek());
$graph->muteWord($alice, 'politics', excludeFollowing: true);  // not from accounts Alice follows
$graph->mutedWords($alice);                                    // entries still in force
$graph->unmuteWord($alice, $id);
```

An entry hides a record holding every one of its words, in any order and any of its sources, so `violent protest` hides "the protest got violent" but not "a violent storm". Matching whole words keeps it an index lookup rather than a scan of every record's text. An expired entry stops applying on its own and is left out of `mutedWords()`. Muted records are left out everywhere hidden accounts are, in the table above. Where the viewer asked for something deliberately, such as a profile, cover a muted record instead of dropping it:

```php
'muted' => new MutedWords(),   // ['spoilers'], or null when it holds none

$graph->mutedWordsIn($alice, [$recordId => $authorDid]);   // record id => muted values
$graph->mutedWordsInText($alice, '🔥', 'status');          // for what is not a record, such as a trending word
```

`MutedWords` is null without a viewer and for the viewer's own records, and honors each entry's sources and `excludeFollowing`.

## Relationship

The `Relationship` view part tells a view how the viewer stands with each record's author, null without a viewer and for the viewer's own records:

```php
'viewer' => new Relationship(),   // {following, followedBy, blocking, blockedBy, muted}
```
