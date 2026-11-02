<?php

namespace SocialDept\AtpAppview\Claims;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Lottery;
use InvalidArgumentException;
use SocialDept\AtpAppview\Claims\Contracts\ClaimChecker;
use SocialDept\AtpAppview\Features\Claims as ClaimsFeature;
use SocialDept\AtpAppview\Models\Record;

/**
 * Verdicts on the claims records make, kept in atp_record_claims. A record
 * written with a new claim waits as pending, a queued refresh checks it with
 * its collection's checker, and reads only ever look at what is stored.
 */
class Claims
{
    public const TABLE = 'atp_record_claims';

    public const VERIFIED = 'verified';

    public const UNVERIFIED = 'unverified';

    public const PENDING = 'pending';

    // How the verdict was reached: the record's own check, its parent's
    // verdict, or its own check while it could have inherited.
    public const DIRECT = 'direct';

    public const INHERITED = 'inherited';

    public const SAMPLED = 'sampled';

    /**
     * Changes a refresh has decided on, by URI, written together at its end.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $changes = [];

    /**
     * The collections whose records make a claim, with their settings.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function collections(): array
    {
        // Read whole: NSIDs contain dots, which config() would take as nesting.
        return (array) config('atp-appview.claims.collections', []);
    }

    /**
     * One collection's settings over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function settings(string $collection): array
    {
        $defaults = Arr::except((array) config('atp-appview.claims', []), ['collections']);

        return array_replace($defaults, self::collections()[$collection] ?? []);
    }

    public static function checker(string $collection): ClaimChecker
    {
        $class = self::collections()[$collection]['checker'] ?? null;

        if (! is_string($class) || ! is_a($class, ClaimChecker::class, true)) {
            throw new InvalidArgumentException("Claims in {$collection} need a checker implementing ClaimChecker.");
        }

        return app($class);
    }

    /**
     * Keep up with records the indexer just wrote, in its transaction. A
     * record whose claim is new or changed goes back to pending, and one that
     * is deleted or no longer makes a claim loses its verdict.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function written(Connection $connection, array $rows, string $now): void
    {
        $collections = self::collections();
        $rows = array_values(array_filter($rows, fn (array $row) => isset($collections[$row['collection']])));

        if ($rows === []) {
            return;
        }

        $known = $connection->table(self::TABLE)->whereIn('uri', array_column($rows, 'uri'))->pluck('claim_hash', 'uri')->all();
        $forget = [];
        $changed = [];

        foreach ($rows as $row) {
            $record = $row['record'] === null ? null : (new Record())->newFromBuilder($row);
            $checker = $record === null ? null : self::checker($row['collection']);
            $claim = $record === null ? null : $checker->claim($record);

            if ($claim === null) {
                $forget[] = $row['uri'];

                continue;
            }

            $parent = $this->parentOf($record);
            $hash = sha1(json_encode([$claim, $parent], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Any other field changing keeps the verdict.
            if (($known[$row['uri']] ?? null) === $hash) {
                continue;
            }

            $changed[] = [
                'uri' => $row['uri'],
                'did' => $row['did'],
                'collection' => $row['collection'],
                'parent' => $parent,
                'host' => $checker->host($record, $claim),
                'claim_hash' => $hash,
                'state' => self::PENDING,
                'method' => self::DIRECT,
                'passes' => 0,
                'failures' => 0,
                'failing_since' => null,
                'checked_at' => null,
                'next_check_at' => $now,
                'last_error' => null,
            ];
        }

        if ($forget !== []) {
            $connection->table(self::TABLE)->whereIn('uri', $forget)->delete();
        }

        if ($changed !== []) {
            $connection->table(self::TABLE)->upsert($changed, ['uri'], array_keys(Arr::except($changed[0], ['uri'])));
        }
    }

    /**
     * The record its collection inherits from, only when it is in the same
     * repo.
     */
    protected function parentOf(Record $record): ?string
    {
        $via = self::settings($record->collection)['inherit']['via'] ?? null;
        $parent = $via === null ? null : data_get($record->record, $via);

        return is_string($parent) && str_starts_with($parent, "at://{$record->did}/") ? $parent : null;
    }

    /**
     * Records due a check, soonest first, for a scheduled refresh.
     *
     * @return array<int, string>
     */
    public function due(int $limit = 500): array
    {
        return $this->connection()->table(self::TABLE)
            ->where('next_check_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('next_check_at')
            ->limit($limit)
            ->pluck('uri')->all();
    }

    /**
     * Check these records in the queue, one job per host.
     *
     * @param  array<int, string>  $uris
     */
    public function queue(array $uris): int
    {
        ClaimsFeature::ensureOn();

        $hosts = $this->connection()->table(self::TABLE)->whereIn('uri', array_values(array_unique($uris)))->pluck('host', 'uri')->all();

        foreach (array_keys(array_flip($hosts)) as $host) {
            RefreshClaims::dispatch((string) $host, array_keys($hosts, $host, true));
        }

        return count($hosts);
    }

    /**
     * Check these records now. A record whose parent is trusted inherits its
     * verdict without a request, unless it is drawn as a sample. Records past
     * their host's limit are left due for the next run.
     *
     * @param  array<int, string>  $uris
     */
    public function refresh(array $uris): void
    {
        ClaimsFeature::ensureOn();

        $now = Carbon::now('UTC');
        $rows = $this->connection()->table(self::TABLE)->whereIn('uri', array_values(array_unique($uris)))->get()->keyBy('uri');
        $parents = $this->connection()->table(self::TABLE)->whereIn('uri', $rows->pluck('parent')->filter()->unique()->values())->get()->keyBy('uri');
        // Hidden records keep their verdicts, so they are read too.
        $records = Record::withoutGlobalScopes()->whereIn('uri', $rows->keys())->whereNull('deleted_at')->get()->keyBy('uri');
        $checks = [];
        $gone = [];
        $this->changes = [];

        foreach ($rows as $uri => $row) {
            if (! isset($records[$uri])) {
                $gone[] = $uri;

                continue;
            }

            $inherit = self::settings($row->collection)['inherit'] ?? null;

            if (! is_array($inherit) || ! $this->trusted($parents[$row->parent] ?? null, $row, $inherit)) {
                $checks[] = [$row, self::DIRECT];
            } elseif ($this->drawn((float) ($inherit['sample_rate'] ?? 0))) {
                $checks[] = [$row, self::SAMPLED];
            } else {
                $this->inherit($row, $now, (int) ($inherit['sample_every'] ?? 604800));
            }
        }

        $checks = $this->withinLimits($checks);

        foreach (collect($checks)->groupBy(fn (array $check) => $check[0]->collection) as $collection => $group) {
            $checker = self::checker($collection);

            foreach ($group->chunk(max(1, (int) (self::settings($collection)['batch'] ?? 25))) as $batch) {
                $verdicts = $checker->check($batch->map(fn (array $check) => $records[$check[0]->uri])->values()->all());

                foreach ($batch as [$row, $method]) {
                    $this->apply($row, $verdicts[$row->uri] ?? Verdict::unknown('The checker gave no verdict.'), $method, $now);
                }
            }
        }

        $this->write($gone);
    }

    /**
     * Whether a record's parent lends it its verdict: verified by its own
     * check for its current claim, in the same repo, and passing enough
     * checks in a row.
     *
     * @param  array<string, mixed>  $inherit
     */
    protected function trusted(?object $parent, object $row, array $inherit): bool
    {
        return $parent !== null
            && $parent->did === $row->did
            && $parent->state === self::VERIFIED
            && $parent->method === self::DIRECT
            && (int) $parent->passes >= (int) ($inherit['trust_after'] ?? 1);
    }

    protected function drawn(float $rate): bool
    {
        return $rate > 0 && Lottery::odds($rate)->choose();
    }

    protected function inherit(object $row, Carbon $now, int $sampleEvery): void
    {
        $this->update($row->uri, [
            'state' => self::VERIFIED,
            'method' => self::INHERITED,
            'failures' => 0,
            'failing_since' => null,
            'checked_at' => $now,
            'next_check_at' => $now->copy()->addSeconds($sampleEvery),
            'last_error' => null,
        ]);
    }

    /**
     * The checks each host's limit allows this minute, counting them against
     * it.
     *
     * @param  array<int, array{0: object, 1: string}>  $checks
     * @return array<int, array{0: object, 1: string}>
     */
    protected function withinLimits(array $checks): array
    {
        $allowed = [];

        foreach (collect($checks)->groupBy(fn (array $check) => $check[0]->host) as $host => $group) {
            $limit = self::settings($group[0][0]->collection)['per_host_per_minute'] ?? null;

            if ($limit === null) {
                array_push($allowed, ...$group->all());

                continue;
            }

            $key = 'atp-appview.claims.host.'.sha1((string) $host);
            $take = min($group->count(), max(0, (int) $limit - RateLimiter::attempts($key)));

            if ($take > 0) {
                RateLimiter::increment($key, 60, $take);
                array_push($allowed, ...$group->take($take)->all());
            }
        }

        return $allowed;
    }

    /**
     * Keep a checker's verdict. Failing checks of any kind never demote a
     * record verified directly inside the grace window, and a failed sample
     * stops the parent's records inheriting until it earns trust again.
     */
    protected function apply(object $row, Verdict $verdict, string $method, Carbon $now): void
    {
        $settings = self::settings($row->collection);

        if ($verdict->result === Verdict::VERIFIED) {
            $this->update($row->uri, [
                'state' => self::VERIFIED,
                'method' => $method,
                // Counted in the database, so records passing together share one update.
                'passes' => $this->connection()->raw('passes + 1'),
                'failures' => 0,
                'failing_since' => null,
                'checked_at' => $now,
                'next_check_at' => $now->copy()->addSeconds((int) ($settings['recheck']['verified'] ?? 86400)),
                'last_error' => null,
            ]);

            return;
        }

        $failures = (int) $row->failures + 1;
        $failingSince = $row->failing_since === null ? $now->copy() : Carbon::parse($row->failing_since, 'UTC');
        $graced = $row->state === self::VERIFIED && $row->method === self::DIRECT && $this->inGrace($settings, $failures, $failingSince, $now);
        $definite = $verdict->result === Verdict::UNVERIFIED;

        $values = [
            'failures' => $failures,
            'failing_since' => $failingSince,
            'checked_at' => $now,
            'next_check_at' => $now->copy()->addSeconds($this->backoff($settings, $failures)),
            'last_error' => $verdict->reason === null ? null : mb_substr($verdict->reason, 0, 1000),
        ];

        if ($definite && $method === self::SAMPLED) {
            $values += ['state' => self::UNVERIFIED, 'method' => self::SAMPLED, 'passes' => 0];
            $this->update($row->parent, ['passes' => 0]);
        } elseif ($graced) {
            // Kept verified, and checked again soon.
        } elseif ($definite || ($row->state === self::VERIFIED && $row->method === self::DIRECT)) {
            $values += ['state' => self::UNVERIFIED, 'method' => self::DIRECT, 'passes' => 0];
        }

        if (($values['state'] ?? null) === self::UNVERIFIED && $definite) {
            $values['next_check_at'] = $now->copy()->addSeconds((int) ($settings['recheck']['unverified'] ?? 21600));
        }

        $this->update($row->uri, $values);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function inGrace(array $settings, int $failures, Carbon $failingSince, Carbon $now): bool
    {
        return $failures < (int) ($settings['grace']['failures'] ?? 3)
            || $failingSince->diffInSeconds($now) < (int) ($settings['grace']['seconds'] ?? 259200);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function backoff(array $settings, int $failures): int
    {
        $steps = array_values((array) ($settings['backoff'] ?? [900]));

        return (int) ($steps[min($failures, count($steps)) - 1] ?? 900);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function update(string $uri, array $values): void
    {
        $values = array_map(fn ($value) => $value instanceof Carbon ? $value->format('Y-m-d H:i:s') : $value, $values);

        $this->changes[$uri] = array_replace($this->changes[$uri] ?? [], $values);
    }

    /**
     * Write the refresh's changes, one update per set of identical values, so
     * a job of five hundred inherited records is one statement, not five
     * hundred.
     *
     * @param  array<int, string>  $gone
     */
    protected function write(array $gone): void
    {
        $connection = $this->connection();
        $grammar = $connection->getQueryGrammar();
        $groups = [];

        foreach ($this->changes as $uri => $values) {
            $key = serialize(array_map(fn ($value) => $value instanceof Expression ? ['raw' => $value->getValue($grammar)] : $value, $values));
            $groups[$key]['values'] = $values;
            $groups[$key]['uris'][] = $uri;
        }

        $this->changes = [];

        $connection->transaction(function () use ($connection, $groups, $gone) {
            foreach (array_chunk($gone, 1000) as $chunk) {
                $connection->table(self::TABLE)->whereIn('uri', $chunk)->delete();
            }

            foreach ($groups as $group) {
                foreach (array_chunk($group['uris'], 1000) as $chunk) {
                    $connection->table(self::TABLE)->whereIn('uri', $chunk)->update($group['values']);
                }
            }
        });
    }

    /**
     * Narrow a query to records whose claim holds: verified by their own
     * check, or inheriting from a parent trusted right now. Read from stored
     * verdicts alone, so a parent losing its verdict takes its records with
     * it at once.
     */
    public function constrain(Builder $query, string $uriColumn): void
    {
        ClaimsFeature::ensureOn();

        $inheriting = array_filter(self::collections(), fn (array $settings) => is_array($settings['inherit'] ?? null));

        $query->whereExists(function ($claim) use ($uriColumn, $inheriting) {
            $claim->selectRaw('1')->from(self::TABLE.' as claim')->whereColumn('claim.uri', $uriColumn)
                ->where(function ($holds) use ($inheriting) {
                    $holds->where(fn ($own) => $own->where('claim.state', self::VERIFIED)->whereIn('claim.method', [self::DIRECT, self::SAMPLED]));

                    if ($inheriting === []) {
                        return;
                    }

                    // A record's own failed check outweighs its parent.
                    $holds->orWhere(fn ($inherited) => $inherited->where('claim.state', '<>', self::UNVERIFIED)->whereExists(function ($parent) use ($inheriting) {
                        $parent->selectRaw('1')->from(self::TABLE.' as parent')
                            ->whereColumn('parent.uri', 'claim.parent')
                            ->whereColumn('parent.did', 'claim.did')
                            ->where('parent.state', self::VERIFIED)
                            ->where('parent.method', self::DIRECT)
                            ->where(function ($trust) use ($inheriting) {
                                foreach ($inheriting as $collection => $settings) {
                                    $trust->orWhere(fn ($trusted) => $trusted->where('claim.collection', $collection)->where('parent.passes', '>=', (int) ($settings['inherit']['trust_after'] ?? 1)));
                                }
                            });
                    }));
                });
        });
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
