# Retention

Part of the [atp-appview docs](../README.md#documentation). Keeping the index small: compressing stored records.

Everything here is off until set in `config/atp-appview/retention.php`, so an app that sets nothing keeps every record as it is.

## Compression

Postgres compresses a large value before storing it, with pglz unless the column says otherwise. LZ4 compresses and decompresses faster, at a similar size for JSON. Set it for the columns holding record bodies, `record` and `repair` on `atp_records`, and on `atp_space_records` when [spaces](spaces.md) are on:

```dotenv
ATP_APPVIEW_RECORD_COMPRESSION=lz4
```

```bash
php artisan appview:compress --dry-run   # what would change
php artisan appview:compress             # set the columns
```

It needs Postgres 14 or later, built with LZ4, and the command says so and fails otherwise. Setting a column's compression changes the catalog only: it takes a brief lock and rewrites nothing. Run the command again after `migrate:fresh`, or set the variable back to empty and run it to return the columns to the server's default.

Only values written from then on use LZ4. Rows already stored keep pglz until rewritten, and values under about 2 KB are stored uncompressed either way. To rewrite the older rows:

```bash
php artisan appview:compress --recompress --seconds=300
```

It works through each table in id order, a chunk at a time (`--chunk`, 1,000 by default), and rewrites only rows whose values use another compression, so a run stopped at `--seconds` picks up where it left off next time. Assigning a column to itself would keep the stored value as it is, so each value is rebuilt, which costs:

- **Space before it saves any.** Every rewritten row is a new row version, and the old ones stay on disk until vacuumed. Plain `VACUUM` (or autovacuum) makes that space reusable, but only `VACUUM FULL` or `pg_repack` returns it to the operating system.
- **Write-ahead log** about the size of what is rewritten, which replicas and backups receive too.
- **Row locks** on each chunk while it is written, so ingest touching the same rows waits for that chunk.

On 50,000 synthetic 3 KB documents, LZ4 stored 13 MB against pglz's 16 MB, rewriting them took about 0.5 s against 1.5 s for pglz, and reading every body took the same time with either. Real records vary, so compare with `pg_column_size(record)` on your own data before rewriting a large index.

### Other databases

| Database | Behavior |
| --- | --- |
| Postgres 14+ with LZ4 | Columns set by `appview:compress` |
| Postgres before 14, or without LZ4 | `appview:compress` fails and says why |
| MySQL | Nothing. InnoDB stores JSON in a binary format of its own, and its compression is a table option (`ROW_FORMAT=COMPRESSED`, or page compression that depends on the filesystem's hole punching), which rebuilds the table and is the operator's choice |
| MariaDB | Nothing. Its `COMPRESSED` column attribute rebuilds the table to add and stops the column from being indexed |
| SQLite | Nothing. It has no column compression |

The command reports that there is nothing to do and succeeds, so the same deploy script runs everywhere.
