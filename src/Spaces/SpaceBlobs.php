<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Support\Cid;
use UnexpectedValueException;

/**
 * The blobs records in followed spaces refer to, kept on a disk.
 *
 * A blob is fetched from its member's PDS with the space's sync credential
 * the first time anyone asks for it, checked against its CID, and kept on
 * spaces.blobs.disk under the space and the CID, so it is fetched once
 * however many viewers load it. Bytes that do not hash to the CID are
 * refused and never kept. A space's blobs are dropped when it is deleted
 * or unfollowed.
 */
class SpaceBlobs
{
    public function __construct(
        protected SpaceSync $sync,
    ) {
    }

    /**
     * Where a blob is kept on the disk, whether or not it is there.
     *
     * @throws InvalidArgumentException when the CID is not a sha2-256 CIDv1
     */
    public function path(SpaceRef $space, string $cid): string
    {
        return $this->directory($space).'/'.Cid::parse($cid)->toString();
    }

    /**
     * Whether a blob is kept on the disk.
     */
    public function has(SpaceRef $space, string $cid): bool
    {
        return $this->disk()->exists($this->path($space, $cid));
    }

    /**
     * A stream of a blob kept on the disk, or null when it is not there.
     *
     * @return resource|null
     */
    public function stream(SpaceRef $space, string $cid)
    {
        return $this->disk()->readStream($this->path($space, $cid));
    }

    /**
     * The size in bytes of a blob kept on the disk.
     */
    public function size(SpaceRef $space, string $cid): int
    {
        return $this->disk()->size($this->path($space, $cid));
    }

    /**
     * Fetch a blob from a member's repo with the space's sync credential,
     * check it against its CID and keep it.
     *
     * @return string|null The bytes, or null when the space is not followed, was deleted or is stale
     *
     * @throws SpacesException when the read fails
     * @throws UnexpectedValueException when the bytes do not hash to the CID, and nothing is kept
     */
    public function fetch(SpaceRef $space, string $did, string $cid): ?string
    {
        $path = $this->path($space, $cid);
        $bytes = $this->sync->blob($space, $did, $cid);

        if ($bytes === null) {
            return null;
        }

        if (! self::matches($bytes, $cid)) {
            throw new UnexpectedValueException("The blob {$did} sent for {$cid} in {$space} does not hash to that CID.");
        }

        $this->disk()->put($path, $bytes);

        return $bytes;
    }

    /**
     * Drop every blob kept for a space.
     */
    public function forget(SpaceRef $space): void
    {
        $this->disk()->deleteDirectory($this->directory($space));
    }

    /**
     * Whether bytes are the blob a CID names: a raw CIDv1 with the bytes'
     * sha2-256 digest.
     */
    public static function matches(string $bytes, string $cid): bool
    {
        try {
            return Cid::fromBinary(Cid::RAW_PREFIX.hash('sha256', $bytes, true))->equals(Cid::parse($cid));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether a string is a CID a blob can be kept under.
     */
    public static function isCid(string $cid): bool
    {
        try {
            Cid::parse($cid);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('atp-appview.spaces.blobs.disk'));
    }

    /**
     * A directory per space, named by a hash of its URI, which holds
     * characters a path cannot.
     */
    protected function directory(SpaceRef $space): string
    {
        $prefix = trim((string) config('atp-appview.spaces.blobs.prefix', 'atp-appview/space-blobs'), '/');

        return ltrim($prefix.'/'.hash('sha256', (string) $space), '/');
    }
}
