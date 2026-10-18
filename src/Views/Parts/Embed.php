<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Contracts\Part;
use SocialDept\AtpAppview\Views\View;

/**
 * The record a field points at, in its view's short form, such as a post's
 * quoted post. One primary key lookup for every target on the page. Null when
 * the field holds no at-uri, or the index does not hold the target.
 */
class Embed implements Part
{
    /**
     * @param  class-string<View>  $view
     */
    public function __construct(
        protected string $path,
        protected string $view,
    ) {
    }

    public function resolve(Collection $records, ?string $viewer): array
    {
        $view = app($this->view);
        $targets = [];

        foreach ($records as $record) {
            $target = data_get($record->record, $this->path);

            if (is_string($target) && str_starts_with($target, 'at://')) {
                $targets[$record->uri] = $target;
            }
        }

        if ($targets === []) {
            return [];
        }

        $found = Record::query()->collection($view->collection())
            ->whereIn('uri', array_values(array_unique($targets)))
            ->get()
            ->keyBy('uri');

        return collect($targets)
            ->filter(fn (string $target) => isset($found[$target]))
            ->map(fn (string $target) => $view->summary($found[$target]))
            ->all();
    }
}
