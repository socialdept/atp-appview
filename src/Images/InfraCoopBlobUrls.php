<?php

namespace SocialDept\AtpAppview\Images;

use CoInfra\Image\UrlBuilder;

/**
 * Resized, reformatted and cached by img.infra.coop, or a private deployment
 * of it. The CDN fetches the blob from the account's PDS itself, so nothing
 * needs resolving here.
 */
class InfraCoopBlobUrls implements BlobUrls
{
    public function __construct(protected UrlBuilder $builder)
    {
    }

    public function url(string $did, string $cid, string $preset, ?string $pds = null): ?string
    {
        return $this->builder->url($did, $cid, ['preset' => $preset]);
    }
}
