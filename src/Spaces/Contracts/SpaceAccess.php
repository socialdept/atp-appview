<?php

namespace SocialDept\AtpAppview\Spaces\Contracts;

use SocialDept\AtpSpaces\SpaceRef;

/**
 * Whether a viewer may read a followed space's records, asked by
 * SpaceRecordQuery before it returns any. The default, SpaceGrants, says
 * yes while the viewer holds a grant from a credential the space's
 * authority issued them. Bind another implementation for an app that
 * already knows, such as the space's managing app.
 */
interface SpaceAccess
{
    /**
     * @param  string|null  $viewer  A DID, or null for a signed-out viewer
     */
    public function allows(SpaceRef $space, ?string $viewer): bool;
}
