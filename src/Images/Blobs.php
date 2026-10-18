<?php

namespace SocialDept\AtpAppview\Images;

/**
 * The URL of a blob a record refers to, for one use, such as an embedded
 * author's avatar. atp-appview.images.presets maps each use to an
 * img.infra.coop preset.
 */
class Blobs
{
    /**
     * @param  mixed  $blob  A {$type: blob, ref: {$link}} value, or the legacy {cid, mimeType}
     * @param  string  $use  author, avatar, banner or icon, or a preset name
     * @param  string|null  $pds  The account's PDS, when the caller already knows it
     */
    public static function url(mixed $blob, string $did, string $use, ?string $pds = null): ?string
    {
        $cid = is_array($blob) ? ($blob['ref']['$link'] ?? $blob['cid'] ?? null) : null;

        if (! is_string($cid) || $cid === '') {
            return null;
        }

        $preset = (string) (config('atp-appview.images.presets')[$use] ?? $use);

        return app(BlobUrls::class)->url($did, $cid, $preset, $pds);
    }
}
