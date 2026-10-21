# Feeds

Part of the [atp-appview docs](../README.md#documentation). Custom feeds in the shapes of Bluesky's feed generators, under Bluesky's names or your own.

Feeds are a [feature](features.md), `Features::feeds()`, off unless listed. A feed is a class that returns a page of AT-URIs, the skeleton. The package serves it, keeps what viewers do with its items, and for your own clients turns the skeleton into views.

## Names

The primitive is Bluesky's: a generator record, a service entry in the DID document, `getFeedSkeleton`, `describeFeedGenerator` and `sendInteractions`, with the same shapes. Every name is config, and Bluesky's are the default, so as shipped this is a working Bluesky feed generator.

An app serving feeds of its own lexicons renames them all. Its feeds are then readable by any client or AppView that adopts the same lexicons, and not by Bluesky clients:

```php
// config/atp-appview/feeds.php
'service' => ['id' => 'example_feedgen', 'type' => 'ExampleFeedGenerator'],

'lexicons' => [
    'generator' => 'com.example.feed.generator',          // the generator record's collection
    'defs' => 'com.example.feed.defs',                    // skeleton item, reasons, interaction tokens
    'skeleton' => 'com.example.feed.getFeedSkeleton',
    'describe' => 'com.example.feed.describeFeedGenerator',
    'interactions' => 'com.example.feed.sendInteractions',
    'feed' => 'com.example.feed.getFeed',                 // hydrated, for your own clients
],
```

`php artisan appview:feeds:lexicons` writes those lexicons into `resources/lexicons`: Bluesky's documents with the names rewritten, so you can publish them as your own. On Bluesky's names it writes nothing. A null name leaves that endpoint off. The hydrated feed is off by default, since Bluesky serves `getFeed` from its AppView rather than from a generator.

## Writing a feed

```php
use SocialDept\AtpAppview\Feeds\Contracts\Feed;
use SocialDept\AtpAppview\Feeds\FeedInteractions;
use SocialDept\AtpAppview\Feeds\FeedItem;
use SocialDept\AtpAppview\Feeds\FeedPage;
use SocialDept\AtpAppview\Feeds\FeedRequest;
use SocialDept\AtpAppview\Models\Record;

class Latest implements Feed
{
    public function __construct(protected FeedInteractions $interactions) {}

    public function describe(): array
    {
        return ['displayName' => 'Latest', 'description' => 'The newest posts.', 'acceptsInteractions' => true];
    }

    public function skeleton(FeedRequest $request): FeedPage
    {
        $less = $request->viewer === null ? [] : array_keys($this->interactions->authors($request->viewer, $request->feed, FeedInteractions::REQUEST_LESS));

        $records = Record::query()->collection('com.example.post')
            ->whereNotIn('did', $less)
            ->orderByDesc('sort_at')
            ->cursorPaginate($request->limit, cursor: $request->cursor);

        return new FeedPage(
            $records->getCollection()->map(fn (Record $record) => new FeedItem($record->uri, feedContext: 'latest'))->all(),
            $records->nextCursor()?->encode(),
        );
    }
}
```

```php
'feeds' => ['latest' => App\Feeds\Latest::class],   // by the generator record's rkey
```

- `FeedRequest` has the feed's AT-URI, the viewer's DID (null signed out), the cursor, the limit (1 to 100) and the viewer's languages from `Accept-Language`.
- An item can be any record's AT-URI, from any collection. `FeedItem::repost()` and `FeedItem::pin()` add a reason. A `feedContext` comes back with the viewer's interactions on that item, so a feed can tell why it showed it.
- A page is held to the limit, and the package adds a `reqId` to each.
- The viewer's DID comes from service auth: the viewer's PDS signs a token for this AppView, bound to the skeleton method, as Bluesky's PDS does for `getFeed`. Without one the request is signed out.

## Hydrated feeds

With `lexicons.feed` named, your own clients ask for a feed with each item as its view, through the views you configured for each collection, with every part (author, moderation, viewer state, muted words). What the viewer should not see is dropped, as `visibleTo()` decides. An item whose collection has no view comes back as `{uri}`, unless the index holds it and hides it from the viewer.

## Interactions

`sendInteractions` keeps what a signed-in viewer did with a feed's items in `atp_feed_interactions`: asked for more or less, opened, saw. Events are stored by their token's name, `requestLess` for `com.example.feed.defs#requestLess`.

```php
$interactions->items($viewer, $feed, FeedInteractions::REQUEST_LESS);    // the items, most recent first
$interactions->authors($viewer, $feed, FeedInteractions::REQUEST_LESS);  // their authors, with counts
$interactions->contexts($viewer, $feed, FeedInteractions::REQUEST_LESS); // the feed contexts, with counts
```

Each takes an optional `since`. `appview:feeds:prune` forgets interactions older than `interactions_days` (90 by default), and `Features::schedule()` runs it daily.

## Saved and pinned feeds

Each viewer's feeds, as Bluesky's `savedFeedsPrefV2` keeps them: saved means listed among their feeds, pinned means also shown as a tab. A PDS keeps only Bluesky's own preferences, so the app keeps these, privately, in `atp_saved_feeds`.

```php
use SocialDept\AtpAppview\Feeds\SavedFeeds;

$saved = app(SavedFeeds::class);

$saved->for($viewer);              // [{type, value, pinned}], pinned first
$saved->pinned($viewer);           // the tabs, in order
$saved->save($viewer, $feedUri);   // saved, not pinned. pin(), unpin() and remove() too
$saved->replace($viewer, $entries); // the whole list, in this order, as an editing screen saves it
```

An entry is a type and a value: a feed's AT-URI under `feed`, or anything the app defines, such as a built-in timeline under `timeline`. A viewer who never changed theirs has the defaults, which name feeds this service runs by rkey:

```php
'saved' => [
    ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
    ['type' => 'feed', 'value' => 'latest', 'pinned' => true],
],
```

Unpinning keeps a feed saved, and removing forgets it. A viewer who removes every feed keeps an empty list rather than getting the defaults back. `Feeds::catalog()` lists the feeds this service runs, with what their generator records say, for a directory such as a Discover list.

## Publishing

Set the publisher's DID and an app password, then publish:

```php
'publisher' => [
    'did' => env('ATP_APPVIEW_FEEDS_PUBLISHER'),
    'password' => env('ATP_APPVIEW_FEEDS_PUBLISHER_PASSWORD'),
],
```

```bash
php artisan appview:feeds:publish           # write each configured feed's generator record
php artisan appview:feeds:publish --prune   # and delete records for feeds no longer configured
```

A record names this AppView's DID as the feed service and keeps its `createdAt` across publishes. An `avatar` in `describe()` is a local image path, uploaded when published. The feature adds the feed service to the DID document, so the AppView's DID must be a `did:web` with an endpoint set (see [XRPC](xrpc.md)). `describeFeedGenerator` lists the published feeds, with the policy `links` you configure.
