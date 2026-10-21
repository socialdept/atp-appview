<?php

namespace SocialDept\AtpAppview\Views\Parts;

use ArrayObject;
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
 * Each is an ArrayObject, so it reads as an array and is a JSON object even
 * when empty: {} rather than [].
 *
 *     'moderation' => new Moderation(),
 */
class Moderation implements Part
{
    public function resolve(Collection $records, ?string $viewer): array
    {
        return array_map(fn ($decision) => new ArrayObject($decision->toArray()), app(Labels::class)->decideMany($records, $viewer));
    }
}
