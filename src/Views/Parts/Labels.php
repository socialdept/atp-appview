<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Contracts\Part;

/**
 * The labels on each record, or on its author, in the shape atproto views
 * carry them: {src, uri, cid, val, cts}. One query for the page.
 *
 *     'labels' => new Labels(),
 *     'authorLabels' => new Labels(author: true),
 */
class Labels implements Part
{
    public function __construct(
        protected bool $author = false,
    ) {
    }

    public function resolve(Collection $records, ?string $viewer): array
    {
        if ($this->author) {
            $labels = Label::query()->live()->whereIn('subject', $records->pluck('did')->unique()->all())->orderBy('cts')->get()
                ->filter(fn (Label $label) => Target::of($label->uri) === Target::ACCOUNT)
                ->groupBy('subject');

            return $records->mapWithKeys(fn (Record $record) => [
                $record->uri => ($labels[$record->did] ?? collect())->map(fn (Label $label) => $label->toView())->values()->all(),
            ])->all();
        }

        $labels = Label::query()->live()->whereIn('uri', $records->pluck('uri')->all())->orderBy('cts')->get()->groupBy('uri');

        return $records->mapWithKeys(fn (Record $record) => [
            $record->uri => ($labels[$record->uri] ?? collect())->map(fn (Label $label) => $label->toView())->values()->all(),
        ])->all();
    }
}
