<?php

namespace SocialDept\AtpAppview\Images;

/**
 * Where a browser loads a blob from, such as an avatar or a publication icon.
 */
interface BlobUrls
{
    /**
     * @param  string  $preset  What the image is for, as an img.infra.coop preset name
     * @param  string|null  $pds  The account's PDS, when the caller already knows it
     */
    public function url(string $did, string $cid, string $preset, ?string $pds = null): ?string;
}
