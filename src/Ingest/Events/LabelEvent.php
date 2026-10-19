<?php

namespace SocialDept\AtpAppview\Ingest\Events;

use SocialDept\AtpAppview\Labels\Target;

/**
 * A label Tack delivered, or its withdrawal when negated.
 */
final class LabelEvent
{
    /**
     * The account the label is about, so the blocklist and the handlers can
     * treat it like any event about a repo.
     */
    public readonly string $did;

    public function __construct(
        public readonly int $id,
        public readonly string $src,
        public readonly string $uri,
        public readonly ?string $cid,
        public readonly string $val,
        public readonly bool $neg,
        public readonly string $cts,
        public readonly ?string $exp,
        public readonly bool $verified,
    ) {
        $this->did = Target::subject($uri);
    }
}
