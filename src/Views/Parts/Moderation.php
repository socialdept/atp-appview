<?php

namespace SocialDept\AtpAppview\Views\Parts;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Views\Contracts\Part;

/**
 * What to do with each record, from the labels on it and on its author:
 * {hide, review, ban, warn, blur, badges, author, noUnauthenticated}, with
 * what is false or empty left out. A signed-out viewer gets hide for authors
 * who asked, with !no-unauthenticated, not to be shown to them. Two queries
 * for the page.
 *
 *     'moderation' => new Moderation(),
 */
class Moderation implements Part
{
    public function resolve(Collection $records, ?string $viewer): array
    {
        return array_map(fn ($decision) => $decision->toArray(), app(Labels::class)->decideMany($records, $viewer));
    }
}
