# Search

Part of the [atp-appview docs](../README.md#documentation). Records by the words in them, Latest and Top, and the accounts the index holds.

Search is a [feature](features.md), `Features::search()`, off unless listed. It searches what the index holds: the collections you name, and accounts in `atp_identities`. Results come back as views and profiles, filtered for the viewer. The methods take Bluesky's search shapes under names you choose.

## Sources

```php
// config/atp-appview/search.php
'collections' => [
    'com.example.post' => [
        'sources' => [
            'text' => ['text'],
            'tags' => ['tags', 'facets.*.features.*.tag'],
        ],
    ],
],
```

Each source is a list of paths into the record, kept in the index as words, the way [muted words](social-graph.md#muted-words) are: lowercased, emoji whole, a leading `#` dropped. Run `php artisan appview:fields` after changing them, which builds the words of records already indexed. A query matches a record holding every one of its words, in any of the collection's sources. Whole words only, so "run" does not find "running".

A source can derive its text instead, for a collection whose content is not words. List a class implementing `Search\Contracts\SearchText` in place of a path:

```php
use SocialDept\AtpAppview\Search\Contracts\SearchText;

class EmojiNames implements SearchText
{
    public function text(array $record, string $collection): string|array
    {
        return EmojiData::names($record['status'] ?? '');   // "fire flame" for 🔥
    }
}

'sources' => ['emoji' => [EmojiNames::class]],
```

## Methods

```php
'xrpc' => [
    'records' => 'com.example.search.searchRecords',         // as app.bsky.feed.searchPosts
    'accounts' => 'com.example.search.searchAccounts',       // as app.bsky.actor.searchActors
    'typeahead' => 'com.example.search.searchAccountsTypeahead',
],
```

None is on by default, since Bluesky's own names only fit an app indexing Bluesky's posts. The same searches are PHP methods on `Search\Search`.

**Records** take `q`, `sort` (`latest` or `top`), `since`, `until`, `author` (a DID or a handle), `tag` (one or more, read from the source named `tags`), `limit` (up to 100) and `cursor`, plus an optional `collection`. They answer `{records, cursor, hitsTotal}`, each record as its collection's view. `mentions`, `lang`, `domain` and `url` are refused with `BadQueryString`, as is a query with no words or tags.

```php
$search->records(new SearchQuery('cats', viewer: $did, sort: Search::TOP));
```

What the viewer should not see is left out before counting and paging: accounts blocked either way or muted, muted words, and what moderation hides or holds for review.

## Latest and Top

**Latest** is matches newest first. **Top** takes the newest thousand matches of the last `window_days` (7 by default) and orders them with the app's ranker. When nothing matches inside the window, it ranks the newest thousand matches instead, so Top is never empty while Latest has results.

The package does the costly lookups: which of the collection's sources each match was found in, and how many records point at it through the references its Top counts:

```php
'top' => [
    'counts' => ['com.example.like' => ['subject.uri'], 'com.example.repost' => ['subject.uri']],
    'window_days' => 7,
],
```

The ranker turns that into scores. The default, `RecentEngagement`, gives one point, plus one per source matched and per record pointing at the match, decayed with age, so a fresh match with some engagement beats an old one with a little more. An app's own implements `Search\Contracts\TopRanker` and is named in `search.ranker`:

```php
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Search\Contracts\TopRanker;
use SocialDept\AtpAppview\Search\Rankers\RecentEngagement;
use SocialDept\AtpAppview\Search\SearchQuery;

class FollowedFirst implements TopRanker
{
    public function scores(array $candidates, SearchQuery $query): array
    {
        $scores = (new RecentEngagement)->scores($candidates, $query);
        $following = $query->viewer === null ? [] : array_flip(app(Graph::class)->following($query->viewer));

        foreach ($candidates as $candidate) {
            if (isset($following[$candidate->did])) {
                $scores[$candidate->uri] *= 20;
            }
        }

        return $scores;
    }
}
```

Each `TopCandidate` has the match's `uri`, `did`, `collection` and `sortAt`, the sources it `matched`, its `counts` keyed `collection:path`, and `hours()` since it was written. A ranker reads anything else it weighs itself, in one query for all the candidates: follows from the [graph](social-graph.md), scores from [standing](standing.md). Top pages through the ranked list, a page at a time.

## Accounts

Account search reads only what the index holds: handles in `atp_identities`, and the display name and description of the first profile source's records. Nothing fetched from Slingshot is searched.

- **Typeahead** matches the start of a handle or of a word in the display name, for completing while typing.
- **Search** needs every word somewhere in the handle, display name or description.

Accounts the viewer follows come first, then, with [Standing](standing.md) on, those with the higher score. Those blocked either way are left out, and muted ones stay, marked. Typeahead answers basic profiles, search full ones, each with `viewer`, the [Relationship](social-graph.md) part, when there is a viewer.
