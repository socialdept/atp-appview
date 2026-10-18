<?php

namespace SocialDept\AtpAppview\Profiles;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\Backfill\Backfills;
use SocialDept\AtpAppview\Images\Blobs;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use Throwable;

/**
 * An account's profile, from an ordered list of profile collections.
 *
 * By default the first source holding a record for the account is its
 * profile, empty fields and all: nothing falls through from a later source.
 * With merge set to `field`, each standard field takes the first source that
 * has it instead. An account no source has a record for is its DID and
 * handle, with displayName left null for clients to fall back to the handle.
 *
 * Records come from the index when it ingests the collection and knows the
 * account. Otherwise they are fetched from Slingshot, in parallel, and cached,
 * "no record" included.
 *
 *     Profiles::source('com.example.actor.profile')->fields(['pronouns', 'website']);
 */
class Profiles
{
    /**
     * The fields every profile has, whichever source supplied it.
     */
    public const STANDARD = ['displayName', 'description', 'avatar', 'banner'];

    /**
     * The fields an author embedded in another view shows.
     */
    public const BASIC = ['did', 'handle', 'displayName', 'avatar'];

    protected const BLOBS = ['avatar', 'banner'];

    /**
     * How long a fetched record, or its absence, is kept.
     */
    protected const FETCHED_TTL = 3600;

    /** @var array<string, ProfileSource> */
    protected array $sources = [];

    public function __construct()
    {
        foreach ((array) config('atp-appview.profiles.sources', []) as $source) {
            $source = ProfileSource::fromConfig($source);
            $this->sources[$source->collection] = $source;
        }
    }

    /**
     * A source, added at the end of the list if it is not already in it, to
     * configure fluently.
     */
    public static function source(string $collection, string $rkey = 'self'): ProfileSource
    {
        $profiles = app(self::class);

        return $profiles->sources[$collection] ??= new ProfileSource($collection, $rkey);
    }

    /**
     * @return array<int, ProfileSource>
     */
    public function sources(): array
    {
        return array_values($this->sources);
    }

    /**
     * Profiles for these accounts, by DID, with every field.
     *
     * @param  array<int, string>  $dids
     * @return array<string, array<string, mixed>>
     */
    public function for(array $dids): array
    {
        return $this->resolve($dids, ['avatar' => 'avatar', 'banner' => 'banner']);
    }

    /**
     * Profiles with only the fields an embedded author shows, its avatar sized
     * for a small slot.
     *
     * @param  array<int, string>  $dids
     * @return array<string, array<string, mixed>>
     */
    public function basic(array $dids): array
    {
        return array_map(
            fn (array $profile) => array_intersect_key($profile, array_flip(self::BASIC)),
            $this->resolve($dids, ['avatar' => 'author']),
        );
    }

    /**
     * @param  array<int, string>  $dids
     * @param  array<string, string>  $images  Blob field => the use its URL is for
     * @return array<string, array<string, mixed>>
     */
    protected function resolve(array $dids, array $images): array
    {
        $dids = array_values(array_unique($dids));

        if ($dids === []) {
            return [];
        }

        $identities = Identity::query()->whereIn('did', $dids)->get(['did', 'handle', 'pds', 'active'])->keyBy('did');
        $records = $this->records($dids, $identities->keys()->all());
        $profiles = [];

        foreach ($dids as $did) {
            $identity = $identities[$did] ?? null;
            $profile = ['did' => $did, 'handle' => $identity?->handle, ...array_fill_keys(self::STANDARD, null)];

            // An inactive account's records are hidden, so is its profile.
            if ($identity !== null && ! $identity->active) {
                $profiles[$did] = $profile;

                continue;
            }

            $built = [...$profile, ...$this->build($records[$did] ?? [])];

            foreach (self::BLOBS as $field) {
                $built[$field] = isset($images[$field]) ? Blobs::url($built[$field], $did, $images[$field], $identity?->pds) : null;
            }

            $profiles[$did] = $built;
        }

        return $profiles;
    }

    /**
     * Each account's record in each source, by DID and collection: one query
     * per indexed source, then one parallel round of fetches for the rest.
     *
     * @param  array<int, string>  $dids
     * @param  array<int, string>  $known  Accounts the index knows
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function records(array $dids, array $known): array
    {
        $records = [];
        $missing = [];
        $backfilling = array_column(app(Backfills::class)->active(), 'collection');

        foreach ($this->sources as $collection => $source) {
            // The index answers only for accounts it knows, in collections it
            // ingests. For anyone else, absence from the index proves nothing.
            $indexed = Collections::ingests($collection) ? $known : [];

            if ($indexed !== []) {
                // By primary key: each profile's uri is known from its DID.
                Record::query()->whereIn('uri', array_map(fn (string $did) => "at://{$did}/{$collection}/{$source->rkey}", $indexed))
                    ->get(['did', 'record'])
                    ->each(function (Record $record) use (&$records, $collection) {
                        $records[$record->did][$collection] = (array) $record->record;
                    });
            }

            // Nor does it while the collection is still being backfilled: an
            // account the index has no record for yet is fetched instead.
            $trusted = in_array($collection, $backfilling, true) ? [] : $indexed;

            foreach (array_diff($dids, $trusted) as $did) {
                if (! isset($records[$did][$collection])) {
                    $missing[] = [$did, $source];
                }
            }
        }

        foreach ($this->fetch($missing) as [$did, $collection, $record]) {
            $records[$did][$collection] = $record;
        }

        return $records;
    }

    /**
     * Fetch records from Slingshot, cached, the uncached ones in parallel.
     *
     * @param  array<int, array{0: string, 1: ProfileSource}>  $wanted
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected function fetch(array $wanted): array
    {
        $found = [];
        $uncached = [];

        if ($wanted === []) {
            return [];
        }

        // One cache read for the page, not one per account.
        $cache = Cache::many(array_map(fn (array $pair) => $this->cacheKey($pair[0], $pair[1]), $wanted));

        foreach ($wanted as [$did, $source]) {
            $cached = $cache[$this->cacheKey($did, $source)] ?? null;

            if ($cached === null) {
                $uncached[] = [$did, $source];
            } elseif ($cached !== false) {
                $found[] = [$did, $source->collection, $cached];
            }
        }

        if ($uncached === []) {
            return $found;
        }

        $url = rtrim((string) config('atp-support.microcosm.slingshot.url', 'https://slingshot.microcosm.blue'), '/').'/xrpc/com.atproto.repo.getRecord';

        $responses = Http::pool(fn ($pool) => array_map(
            fn (array $pair) => $pool->timeout(5)->get($url, ['repo' => $pair[0], 'collection' => $pair[1]->collection, 'rkey' => $pair[1]->rkey]),
            $uncached,
        ));

        $remember = [];

        foreach ($uncached as $i => [$did, $source]) {
            $response = $responses[$i] ?? null;

            if ($response instanceof Throwable || $response === null) {
                continue;
            }

            if ($response->successful() && is_array($value = $response->json('value'))) {
                $remember[$this->cacheKey($did, $source)] = $value;
                $found[] = [$did, $source->collection, $value];
            } elseif ($response->json('error') === 'RecordNotFound') {
                // Remember the absence too. Any other failure is tried again next time.
                $remember[$this->cacheKey($did, $source)] = false;
            }
        }

        if ($remember !== []) {
            Cache::putMany($remember, self::FETCHED_TTL);
        }

        return $found;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records  This account's record per source collection
     * @return array<string, mixed>
     */
    protected function build(array $records): array
    {
        $profile = [];
        $chosen = null;

        foreach ($this->sources as $collection => $source) {
            if (! isset($records[$collection])) {
                continue;
            }

            $chosen ??= $source;

            foreach (self::STANDARD as $field) {
                $value = $source->standard($records[$collection], $field);

                // Whole record: only the first source's fields. Per field:
                // the first source that sets each one.
                if ($chosen === $source || (config('atp-appview.profiles.merge') === 'field' && ($profile[$field] ?? null) === null)) {
                    $profile[$field] = $value;
                }
            }

            if (config('atp-appview.profiles.merge') !== 'field') {
                break;
            }
        }

        if ($chosen === null) {
            return [];
        }

        foreach (['displayName', 'description'] as $field) {
            if (! is_string($profile[$field] ?? null) || trim($profile[$field]) === '') {
                $profile[$field] = null;
            }
        }

        return [...$profile, ...$chosen->extra($records[$chosen->collection])];
    }

    protected function cacheKey(string $did, ProfileSource $source): string
    {
        return "atp-appview:profile:{$source->collection}:{$source->rkey}:{$did}";
    }
}
