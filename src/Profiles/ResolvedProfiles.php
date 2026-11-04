<?php

namespace SocialDept\AtpAppview\Profiles;

/**
 * Profiles already built this request or job, by the image uses they were
 * built for, then by DID. Bound scoped, so it empties between requests and
 * jobs while Profiles itself, and the sources configured on it, stay.
 */
class ResolvedProfiles
{
    /** @var array<string, array<string, array<string, mixed>>> */
    protected array $built = [];

    /**
     * @param  array<int, string>  $dids
     * @return array<string, array<string, mixed>>
     */
    public function get(string $images, array $dids): array
    {
        return array_intersect_key($this->built[$images] ?? [], array_flip($dids));
    }

    /**
     * @param  array<string, array<string, mixed>>  $profiles
     */
    public function put(string $images, array $profiles): void
    {
        $this->built[$images] = [...($this->built[$images] ?? []), ...$profiles];
    }

    public function forget(string $did): void
    {
        foreach (array_keys($this->built) as $images) {
            unset($this->built[$images][$did]);
        }
    }
}
