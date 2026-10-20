# Social graph

Part of the [atp-appview docs](../README.md#documentation). Follows and blocks from the collections you name, private mutes, and where they apply.

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
| Notifications | Nothing from hidden accounts. Unblocking or unmuting brings them back |

The `Relationship` view part tells a view how the viewer stands with each record's author, null without a viewer and for the viewer's own records:

```php
'viewer' => new Relationship(),   // {following, followedBy, blocking, blockedBy, muted}
```
