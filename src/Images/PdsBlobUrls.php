<?php

namespace SocialDept\AtpAppview\Images;

use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * The original blob, straight from the account's PDS. No resizing, so the
 * preset is ignored.
 */
class PdsBlobUrls implements BlobUrls
{
    public function url(string $did, string $cid, string $preset, ?string $pds = null): ?string
    {
        try {
            $pds ??= Resolver::resolvePds($did);
        } catch (Throwable) {
            return null;
        }

        if ($pds === null) {
            return null;
        }

        return rtrim($pds, '/').'/xrpc/com.atproto.sync.getBlob?'.http_build_query(['did' => $did, 'cid' => $cid]);
    }
}
