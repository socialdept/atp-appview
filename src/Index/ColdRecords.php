<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Cold records: rows past their collection's atp-appview.retention.cold
 * window keep everything but their body, which is fetched again when read.
 *
 * hollow() clears the bodies, for appview:prune. The row keeps its uri, cid,
 * dates, field values, counts and references, so lists, filters and counts
 * read it as before. hollowed_at marks it, and a newer version of the
 * record stores its body again.
 *
 * thaw() fills the bodies back in for whatever reads them: one cache read
 * and one parallel round of fetches per call, from Slingshot or each
 * record's PDS. A fetched body must carry the stored CID, and when
 * atp-cbor is installed its CID is computed and compared too. Bodies are
 * cached by CID, so each version is fetched once. A record that cannot be
 * fetched is left without a body.
 */
class ColdRecords
{
    public const CACHE = 'atp-appview:cold:';

    /**
     * How long a failed fetch is remembered before it is tried again.
     */
    public const FAILED_TTL = 300;

    /**
     * Days before a record in this collection is hollowed, from
     * atp-appview.retention.cold, or null to keep its body.
     */
    public static function days(string $collection): ?int
    {
        $map = (array) config('atp-appview.retention.cold', []);
        // Read whole: NSIDs contain dots, which config() would take as nesting.
        $days = array_key_exists($collection, $map) ? $map[$collection] : ($map['*'] ?? null);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * Whether a row is cold: alive, hollowed, and holding no body.
     */
    public static function isCold(object $row): bool
    {
        $attributes = $row instanceof Model ? $row->getAttributes() : (array) $row;

        return ($attributes['hollowed_at'] ?? null) !== null
            && ($attributes['deleted_at'] ?? null) === null
            && ($attributes['record'] ?? null) === null;
    }

    /**
     * Clear the bodies of live records older than their collection's window,
     * a chunk at a time in sort order, until the deadline. Records an ingest
     * repair rewrote keep theirs, since a fetched body would be the
     * unrepaired one.
     *
     * Where each collection's pass got to is kept in the cache, so a run
     * reads only rows that aged since. A pass starts over once a day, for
     * records a backfill indexed with an older date after it went by. A
     * cache that forgets only costs a pass from the start.
     *
     * @param  array<int, string>  $collections
     * @return array{counts: array<string, int>, done: bool}
     */
    public function hollow(Connection $connection, array $collections, int $chunk, float $until, bool $dryRun = false): array
    {
        $counts = [];
        $now = Carbon::now('UTC');

        foreach ($collections as $collection) {
            $days = self::days($collection);

            if ($days === null) {
                continue;
            }

            $cutoff = $now->clone()->subDays($days)->format('Y-m-d H:i:s.u');
            $warm = fn () => $connection->table('atp_records')
                ->where('collection', $collection)
                ->where('sort_at', '<', $cutoff)
                ->whereNull('deleted_at')
                ->whereNull('hollowed_at')
                ->whereNull('repair')
                ->whereNotNull('record');

            if ($dryRun) {
                if (($found = $warm()->count()) > 0) {
                    $counts[$collection] = $found;
                }

                continue;
            }

            $key = self::CACHE."pass:{$collection}";
            $pass = Cache::get($key);

            if (! is_array($pass) || Carbon::parse($pass['started'])->lt($now->clone()->subDay())) {
                $pass = ['started' => $now->toIso8601ZuluString(), 'sort_at' => null, 'uri' => null];
            }

            do {
                if (microtime(true) >= $until) {
                    Cache::forever($key, $pass);

                    return ['counts' => $counts, 'done' => false];
                }

                // Every row is read past the pass's position, warm or not,
                // so cold rows are walked once, not on every run.
                $rows = $connection->table('atp_records')
                    ->where('collection', $collection)
                    ->where('sort_at', '<', $cutoff)
                    ->when($pass['sort_at'] !== null, fn ($query) => $query->where('sort_at', '>=', $pass['sort_at'])->where(
                        fn ($query) => $query->where('sort_at', '>', $pass['sort_at'])->orWhere(fn ($query) => $query->where('sort_at', $pass['sort_at'])->where('uri', '>', $pass['uri'])),
                    ))
                    ->orderBy('sort_at')->orderBy('uri')
                    ->limit($chunk)
                    ->get(['uri', 'sort_at']);

                if ($rows->isEmpty()) {
                    break;
                }

                $hollowed = $warm()->whereIn('uri', $rows->pluck('uri')->all())
                    ->update(['record' => null, 'hollowed_at' => $now->format('Y-m-d H:i:s')]);

                if ($hollowed > 0) {
                    $counts[$collection] = ($counts[$collection] ?? 0) + $hollowed;
                }

                $last = $rows->last();
                $pass['sort_at'] = (string) $last->sort_at;
                $pass['uri'] = (string) $last->uri;
            } while ($rows->count() === $chunk);

            Cache::forever($key, $pass);
        }

        return ['counts' => $counts, 'done' => true];
    }

    /**
     * Fill in the bodies of the cold records among these, in place: models
     * get the body as their record attribute, without marking it changed,
     * and query rows get it as JSON, as they hold it.
     *
     * @param  iterable<object>  $records  Models or rows with uri, cid, record, hollowed_at and deleted_at
     * @return array<int, string> The uris still without a body
     */
    public function thaw(iterable $records): array
    {
        $cold = [];

        foreach ($records as $record) {
            if (self::isCold($record) && is_string($record->cid ?? null)) {
                $cold[(string) $record->uri][] = $record;
            }
        }

        if ($cold === []) {
            return [];
        }

        $bodies = $this->bodies(array_map(fn (array $rows) => (string) $rows[0]->cid, $cold));

        foreach ($cold as $uri => $rows) {
            if (! isset($bodies[$uri])) {
                continue;
            }

            foreach ($rows as $row) {
                if ($row instanceof Model) {
                    $row->setAttribute('record', $bodies[$uri]);
                    $row->syncOriginalAttribute('record');
                } else {
                    $row->record = json_encode($bodies[$uri], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return array_values(array_diff(array_keys($cold), array_keys($bodies)));
    }

    /**
     * The body cached for a version of a record, without fetching.
     *
     * @return array<string, mixed>|null
     */
    public function cached(?string $cid): ?array
    {
        $body = $cid === null ? null : Cache::get(self::CACHE.$cid);

        return is_array($body) ? $body : null;
    }

    /**
     * Bodies by uri, for the versions asked: cached ones first, the rest in
     * one parallel round of fetches.
     *
     * @param  array<string, string>  $wanted  CID by uri
     * @return array<string, array<string, mixed>>
     */
    public function bodies(array $wanted): array
    {
        $found = [];
        $missing = [];
        $cache = Cache::many(array_map(fn (string $cid) => self::CACHE.$cid, array_values($wanted)));

        foreach ($wanted as $uri => $cid) {
            $cached = $cache[self::CACHE.$cid] ?? null;

            if (is_array($cached)) {
                $found[$uri] = $cached;
            } elseif ($cached !== false) {
                $missing[$uri] = $cid;
            }
        }

        if ($missing === []) {
            return $found;
        }

        $requests = $this->requests($missing);
        $responses = $requests === [] ? [] : Http::pool(fn ($pool) => array_map(
            fn (array $request) => $pool->as($request['uri'])->timeout($this->timeout())->get($request['url'], $request['query']),
            $requests,
        ));

        $remember = [];
        $failed = [];

        foreach ($missing as $uri => $cid) {
            $body = $this->verified($responses[$uri] ?? null, $cid);

            if ($body === null) {
                $failed[self::CACHE.$cid] = false;

                continue;
            }

            $found[$uri] = $body;
            $remember[self::CACHE.$cid] = $body;
        }

        if ($remember !== []) {
            Cache::putMany($remember, (int) config('atp-appview.retention.cold_fetch.cache_ttl', 86400));
        }

        if ($failed !== []) {
            Cache::putMany($failed, self::FAILED_TTL);
        }

        return $found;
    }

    /**
     * A getRecord request per record, to Slingshot or to its repo's PDS.
     *
     * @param  array<string, string>  $missing  CID by uri
     * @return array<int, array{uri: string, url: string, query: array<string, string>}>
     */
    protected function requests(array $missing): array
    {
        $from = (string) config('atp-appview.retention.cold_fetch.from', 'slingshot');
        $slingshot = rtrim((string) config('atp-support.microcosm.slingshot.url', 'https://slingshot.microcosm.blue'), '/');
        $hosts = [];
        $requests = [];

        foreach ($missing as $uri => $cid) {
            if (! preg_match('#^at://([^/]+)/([^/]+)/([^/]+)$#', $uri, $match)) {
                continue;
            }

            [, $did, $collection, $rkey] = $match;

            if ($from === 'pds') {
                if (! array_key_exists($did, $hosts)) {
                    try {
                        $hosts[$did] = Resolver::resolvePds($did);
                    } catch (Throwable) {
                        $hosts[$did] = null;
                    }
                }

                $host = $hosts[$did];
            } else {
                $host = $slingshot;
            }

            if (is_string($host) && $host !== '') {
                $requests[] = [
                    'uri' => $uri,
                    'url' => rtrim($host, '/').'/xrpc/com.atproto.repo.getRecord',
                    'query' => ['repo' => $did, 'collection' => $collection, 'rkey' => $rkey, 'cid' => $cid],
                ];
            }
        }

        return $requests;
    }

    /**
     * The body a response carries, when it is the version stored.
     *
     * @return array<string, mixed>|null
     */
    protected function verified(mixed $response, string $cid): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $value = $response->json('value');

        if (! is_array($value) || $response->json('cid') !== $cid) {
            return null;
        }

        $computed = RecordCid::of(json_decode($response->body())->value ?? null);

        return $computed === null || $computed === $cid ? $value : null;
    }

    protected function timeout(): int
    {
        return max(1, (int) config('atp-appview.retention.cold_fetch.timeout', 5));
    }
}
