<?php

namespace SocialDept\AtpAppview\Spaces;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use LogicException;
use SocialDept\AtpAppview\Features\Spaces as SpacesFeature;
use SocialDept\AtpAppview\Spaces\Contracts\ExpiringSpaceAccess;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Where a viewer loads a blob a space record refers to: a signed URL to
 * the AppView's space blob route, since a member's PDS serves space blobs
 * only to a credential. Signing works for an <img>, which sends no header
 * and, from another origin, no cookie, and the same in both auth modes.
 *
 * A URL is signed only for a viewer SpaceAccess allows, and expires with
 * their access, as ExpiringSpaceAccess reports it, and within
 * spaces.blobs.lifetime seconds either way. Until then it is a bearer
 * link: anyone it is shared with can load that one blob.
 */
class SpaceBlobUrls
{
    public const ROUTE = 'atp-appview.space-blob';

    public const DEFAULT_TYPE = 'application/octet-stream';

    public function __construct(
        protected SpaceAccess $access,
    ) {
    }

    /**
     * A signed URL to one blob of one member in a space, or null when the
     * viewer may not read the space.
     *
     * @param  string  $did  The member whose repo holds the blob
     * @param  string|null  $viewer  A DID, or null for a signed-out viewer
     * @param  string|null  $mimeType  The type the record's blob ref gives, sent as the Content-Type
     *
     * @throws LogicException when the spaces feature is off
     */
    public function url(SpaceRef $space, string $did, string $cid, ?string $viewer, ?string $mimeType = null): ?string
    {
        SpacesFeature::ensureOn();

        $expires = $this->expires($space, $viewer);

        if ($expires === null || $cid === '') {
            return null;
        }

        $type = self::mimeType($mimeType);

        return URL::temporarySignedRoute(self::ROUTE, $expires, array_filter([
            'did' => $did,
            'cid' => $cid,
            'space' => (string) $space,
            'type' => $type === self::DEFAULT_TYPE ? null : $type,
        ]), (bool) config('atp-appview.spaces.blobs.absolute', true));
    }

    /**
     * A signed URL to a blob as a space record refers to it, with the type
     * its ref gives.
     *
     * @param  mixed  $blob  A {$type: blob, ref: {$link}, mimeType} value, or the legacy {cid, mimeType}
     *
     * @throws LogicException when the spaces feature is off
     */
    public function blob(SpaceRef $space, mixed $blob, string $did, ?string $viewer): ?string
    {
        $cid = is_array($blob) ? ($blob['ref']['$link'] ?? $blob['cid'] ?? null) : null;

        if (! is_string($cid) || $cid === '') {
            return null;
        }

        $type = $blob['mimeType'] ?? null;

        return $this->url($space, $did, $cid, $viewer, is_string($type) ? $type : null);
    }

    /**
     * A MIME type fit to send, or application/octet-stream.
     */
    public static function mimeType(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        return preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $type) === 1 ? $type : self::DEFAULT_TYPE;
    }

    /**
     * The longest a URL lasts, in seconds.
     */
    public static function lifetime(): int
    {
        return max(1, (int) config('atp-appview.spaces.blobs.lifetime', 600));
    }

    /**
     * When a URL for this viewer expires: with their access, and within
     * the lifetime. Null when they have none.
     */
    protected function expires(SpaceRef $space, ?string $viewer): ?CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $cap = $now->addSeconds(self::lifetime());

        if ($this->access instanceof ExpiringSpaceAccess) {
            $until = $this->access->until($space, $viewer);

            if ($until === null || $until->getTimestamp() <= $now->getTimestamp()) {
                return null;
            }

            return $until->lessThan($cap) ? $until : $cap;
        }

        return $this->access->allows($space, $viewer) ? $cap : null;
    }
}
