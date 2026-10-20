# Blocklist

Part of the [atp-appview docs](../README.md#documentation). Keeping repos, collections and hosts out of the index.

Some repos are spam, and some hosts bridge in content an AppView does not want. Block rules keep their records out of the index before any handler sees them:

```bash
php artisan appview:block did:plc:abc123 --note="known spammer"
php artisan appview:block '*.bridge.example' --collection=app.example.post
php artisan appview:block did:plc:abc123 --collection=app.example.graph.* --purge
php artisan appview:block --file=blocklist.txt --purge

php artisan appview:blocks                  # every rule and how many records it dropped
php artisan appview:unblock did:plc:abc123
```

A rule names a **subject**, either a DID or a PDS host, and a **collection**:

- A host can carry a wildcard. `*.bridge.example` covers `pds.bridge.example` and any other subdomain, but not `bridge.example` itself. A scheme or path is stripped, so `https://pds.example/` works too.
- The collection defaults to every collection. It can also be an exact NSID, or a prefix such as `app.example.graph.*`.

A file holds one rule per line, `subject [collection]`, with anything after `#` kept as the note:

```text
did:plc:abc123                         # known spammer
*.bridge.example  app.example.post     # bridged feeds
```

## Where rules apply

- **At ingest.** Matching record events are dropped before the handlers run, then acknowledged like any other, so Tap does not redeliver them. Identity events always pass, so handles stay current. A running consumer picks up a changed rule before its next batch.
- **In Tap.** A blanket rule, one covering every collection, has Tap stop following the repos it covers, so no backfill is spent on them. For a host, that is every repo whose PDS is already known to be on it. Tap adds repos back by itself, on a firehose commit to its signal collection or when its crawler enumerates, so whenever an event shows a blocked repo has returned, the consumer removes it again, at most once every ten minutes per repo.
- **In discovery.** `appview:discover` leaves out repos a blanket rule covers.
- **With `--purge`.** Records already indexed are deleted outright, not tombstoned, since their authors did not delete them.

Removing a rule has Tap backfill the repos it covered, since the records it dropped were never indexed. A repo Tap had stopped following is added back. One it still follows is removed and added again, which makes Tap backfill it from scratch. Tap picks pending repos in no set order (on SQLite, in practice, oldest first), so during a large backfill a re-added repo can wait behind every repo still pending.

## Host rules

Tap events carry only a DID, so host rules need each repo's PDS. It is resolved from the DID document, through the PLC directory for `did:plc` or `/.well-known/did.json` for `did:web`, many at a time, and kept on `atp_identities` for `blocklist.pds_ttl` seconds (a day). Nothing is resolved while no host rule exists.

A DID document that cannot be fetched never blocks a repo: its records pass, and it is tried again once the TTL passes. Purging under a host rule first resolves every indexed repo whose PDS is not known yet. Around 3,000 repos take a little over a minute.
