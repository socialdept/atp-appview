# Feed lexicon templates

Bluesky's feed generator lexicons, from [bluesky-social/atproto](https://github.com/bluesky-social/atproto) (MIT or Apache 2.0), that `appview:feeds:lexicons` rewrites into an app's own namespace. They are not loaded as lexicons.

- `generator.json`, `getFeedSkeleton.json`, `describeFeedGenerator.json` and `sendInteractions.json` are Bluesky's, unchanged.
- `defs.json` keeps only what feed generators use from `app.bsky.feed.defs`: the skeleton item, its reasons, the interaction and the tokens.
- `getFeed.json` is this package's: a hydrated feed for an AppView that runs the feed itself, each item's view as `unknown`.
