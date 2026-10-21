<?php

namespace SocialDept\AtpAppview\Views;

use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Models\Record;

/**
 * Records by AT-URI turned into views for a viewer, through the first view
 * configured for each collection: for lists that come as URIs, such as a
 * feed's skeleton or search results. What the viewer should not see is left
 * out, and a URI whose collection has no view is passed on as {uri} unless
 * the index hides it.
 */
class UriViews
{
    /**
     * Each URI's view, as the viewer may see it. A URI missing from the
     * result is one the viewer should not see, or one the index does not
     * hold for a collection with a view.
     *
     * @param  array<int, string>  $uris
     * @return array<string, array<string, mixed>>
     */
    public function views(array $uris, ?string $viewer): array
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
