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

**Latest** is matches newest first. **Top** ranks the matches of the last `window_days` (7 by default) by signals the collection names, decayed by age, so a fresh record with some engagement beats an old one with a little more:

```php
'top' => [
    'counts' => ['com.example.like' => ['subject.uri' => 1], 'com.example.repost' => ['subject.uri' => 2]],
    'following' => 5,
    'window_days' => 7,
],
```

- `counts`: records pointing at the match, as `Count` view parts count them, each with a weight.
- `following`: added when the viewer follows the author.
- Every match also scores for each of the collection's sources it matched in, so a word in the text outranks the same word only in a tag.

Top ranks the newest thousand matches in the window, a page at a time.

## Accounts

Account search reads only what the index holds: handles in `atp_identities`, and the display name and description of the first profile source's records. Nothing fetched from Slingshot is searched.

- **Typeahead** matches the start of a handle or of a word in the display name, for completing while typing.
- **Search** needs every word somewhere in the handle, display name or description.

Accounts the viewer follows come first. Those blocked either way are left out, and muted ones stay, marked. Typeahead answers basic profiles, search full ones, each with `viewer`, the [Relationship](social-graph.md) part, when there is a viewer.
