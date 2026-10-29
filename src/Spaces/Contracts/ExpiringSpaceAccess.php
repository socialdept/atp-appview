<?php

namespace SocialDept\AtpAppview\Spaces\Contracts;

use Carbon\CarbonImmutable;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * A SpaceAccess that knows when a viewer's access ends, as SpaceGrants
 * does. SpaceBlobUrls signs a blob URL to expire no later than that. For
 * a SpaceAccess without an end, URLs last spaces.blobs.lifetime.
 */
interface ExpiringSpaceAccess extends SpaceAccess
{
    /**
     * When the viewer's access to the space ends, or null when they have
     * none.
     *
     * @param  string|null  $viewer  A DID, or null for a signed-out viewer
     */
    public function until(SpaceRef $space, ?string $viewer): ?CarbonImmutable;
}
