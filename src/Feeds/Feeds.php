<?php

namespace SocialDept\AtpAppview\Feeds;

use Illuminate\Support\Str;
use SocialDept\AtpAppview\Features\Feeds as FeedsFeature;
use SocialDept\AtpAppview\Feeds\Contracts\Feed;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpAppview\Views\View;

/**
 * The feeds this service runs: finding one by its generator record's
 * AT-URI, running it for a viewer, and turning a skeleton into views for
 * the app's own clients.
 */
class Feeds
{
    public const MAX_LIMIT = 100;

    /**
     * A lexicon of the primitive, by key in atp-appview.feeds.lexicons, or
     * null when the app left it off.
     */
    public static function lexicon(string $key): ?string
    {
        $nsid = config("atp-appview.feeds.lexicons.{$key}");

        return is_string($nsid) && $nsid !== '' ? $nsid : null;
    }

    /**
     * The DID whose repo holds the generator records.
     */
    public static function publisher(): ?string
    {
        $did = config('atp-appview.feeds.publisher.did');

        return is_string($did) && str_starts_with($did, 'did:') ? $did : null;
    }

    /**
     * @return array<string, class-string<Feed>> By rkey
     */
    public static function configured(): array
    {
        return (array) config('atp-appview.feeds.feeds', []);
    }

    /**
     * Every feed this service runs that has an address, with what its
     * generator record says, for a directory such as a Discover list.
     *
     * @return array<string, array<string, mixed>> By AT-URI: uri, rkey and describe()'s fields
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::configured() as $rkey => $class) {
            $feed = app($class);

            if ($feed instanceof Feed && ($uri = self::uri((string) $rkey)) !== null) {
                $catalog[$uri] = ['uri' => $uri, 'rkey' => (string) $rkey, ...$feed->describe()];
            }
        }

        return $catalog;
    }

    /**
     * The AT-URI of a configured feed's generator record.
     */
    public static function uri(string $rkey): ?string
    {
        $publisher = self::publisher();
        $collection = self::lexicon('generator');

        return $publisher === null || $collection === null ? null : "at://{$publisher}/{$collection}/{$rkey}";
    }

    /**
     * The feed a generator record's AT-URI names, when this service runs
     * it: in the publisher's repo, in the generator collection, under a
     * configured rkey.
     */
    public function find(string $uri): ?Feed
    {
        if (! preg_match('#^at://([^/]+)/([^/]+)/([^/]+)$#', $uri, $match)) {
            return null;
        }

        [, $repo, $collection, $rkey] = $match;
        $class = self::configured()[$rkey] ?? null;

        if ($class === null || $repo !== self::publisher() || $collection !== self::lexicon('generator')) {
            return null;
        }

        $feed = app($class);

        return $feed instanceof Feed ? $feed : null;
    }

    /**
     * A page of a feed's skeleton, as the skeleton endpoint answers.
     *
     * @return array{feed: array<int, array<string, mixed>>, cursor?: string, reqId: string}|null Null for a feed this service does not run
     */
    public function skeleton(string $uri, ?string $viewer, ?string $cursor = null, int $limit = 50, array $languages = []): ?array
    {
        FeedsFeature::ensureOn();

        $feed = $this->find($uri);

        if ($feed === null) {
            return null;
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $page = $feed->skeleton(new FeedRequest($uri, $viewer, $cursor === '' ? null : $cursor, $limit, $languages));

        return array_filter([
            'feed' => array_map(fn (FeedItem $item) => $item->toArray(), array_slice($page->items(), 0, $limit)),
            'cursor' => $page->cursor,
            'reqId' => (string) Str::uuid(),
        ], fn ($value) => $value !== null);
    }

    /**
     * A page of a feed with each item as its view, for the app's own
     * clients. Items the viewer should not see are dropped, and an item
     * whose collection has no view comes back as its bare URI.
     *
     * @return array{feed: array<int, array<string, mixed>>, cursor?: string, reqId: string}|null
     */
    public function hydrated(string $uri, ?string $viewer, ?string $cursor = null, int $limit = 50, array $languages = []): ?array
    {
        $skeleton = $this->skeleton($uri, $viewer, $cursor, $limit, $languages);

        if ($skeleton === null) {
            return null;
        }

        $views = $this->views(array_column($skeleton['feed'], 'post'), $viewer);

        $skeleton['feed'] = array_values(array_filter(array_map(function (array $item) use ($views) {
            if (! array_key_exists($item['post'], $views)) {
                return null;
            }

            return ['post' => $views[$item['post']]] + $item;
        }, $skeleton['feed'])));

        return $skeleton;
    }

    /**
     * Each URI's view, as the viewer may see it. A URI missing from the
     * result is one the viewer should not see, or one the index does not
     * hold for a collection with a view.
     *
     * @param  array<int, string>  $uris
     * @return array<string, array<string, mixed>>
     */
    protected function views(array $uris, ?string $viewer): array
    {
        $uris = array_values(array_unique($uris));

        if ($uris === []) {
            return [];
        }

        $byCollection = [];

        foreach (RecordValues::views() as $class) {
            $view = app($class);

            if ($view instanceof View) {
                $byCollection[$view->collection()] ??= $view;
            }
        }

        $indexed = Record::query()->whereIn('uri', $uris)->pluck('uri')->all();
        $visible = Record::query()->whereIn('uri', $uris)->visibleTo($viewer)->get()->keyBy('uri');
        $views = [];

        foreach ($visible->groupBy('collection') as $collection => $records) {
            if (! isset($byCollection[$collection])) {
                continue;
            }

            foreach (app(Hydrator::class)->hydrate($byCollection[$collection], $records->values(), $viewer) as $i => $view) {
                $views[$records->values()[$i]->uri] = $view;
            }
        }

        foreach ($uris as $uri) {
            $collection = explode('/', $uri)[3] ?? '';

            // Without a view, a URI the index does not hide is passed on as is.
            if (! isset($byCollection[$collection]) && (! in_array($uri, $indexed, true) || $visible->has($uri))) {
                $views[$uri] = ['uri' => $uri];
            }
        }

        return $views;
    }
}
