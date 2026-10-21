<?php

namespace SocialDept\AtpAppview\Features;

use LogicException;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Search\Search as SearchService;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\Handlers\SearchAccountsHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\SearchAccountsTypeaheadHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\SearchRecordsHandler;

/**
 * Search over the index: records of the collections atp-appview.search
 * names, by the words of their sources, and the accounts the index holds.
 * Keeps each source as word fields, and serves Bluesky's search shapes
 * under the app's names. Adds no tables.
 *
 * Off, nothing is kept or served, and the Search API refuses, naming this
 * feature.
 */
class Search implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview->config('search');

        foreach (SearchService::fields() as [$collection, $name, $declared]) {
            $appview->field($collection, $name, $declared);
        }

        foreach (['records' => SearchRecordsHandler::class, 'accounts' => SearchAccountsHandler::class, 'typeahead' => SearchAccountsTypeaheadHandler::class] as $key => $handler) {
            if (is_string($nsid = config("atp-appview.search.xrpc.{$key}")) && $nsid !== '') {
                $appview->xrpc($nsid, $handler, 'query', Auth::Optional);
            }
        }
    }

    public static function on(): bool
    {
        return Features::enabled(self::class);
    }

    /**
     * @throws LogicException when the feature is off
     */
    public static function ensureOn(): void
    {
        if (! self::on()) {
            throw new LogicException('Search needs the search feature: add Features::search() to atp-appview.features.');
        }
    }
}
