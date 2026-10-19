<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Looks one account up again from its DID document, bypassing every cache:
 * its PDS, and its handle when the handle resolves back to the DID. The
 * profile cached from Slingshot is dropped too, so a changed profile shows
 * straight away.
 *
 * Handles and PDSes otherwise change only when Tap reports an identity event
 * or a lookup's TTL passes, which can leave an account showing old ones.
 */
class IdentityRefresh
{
    /**
     * @return array{handle: string|null, pds: string|null, changed: array<string, array{0: string|null, 1: string|null}>}
     *
     * @throws Throwable when the DID document cannot be read
     */
    public function refresh(string $did): array
    {
        Resolver::clearDidCache($did);
        Resolver::clearPdsCache($did);

        $document = Resolver::resolveDid($did, false);
        $pds = $document->getPdsEndpoint() === null ? null : rtrim($document->getPdsEndpoint(), '/');
        $handle = $document->getHandle();

        // A handle counts only if it points back at this DID.
        if ($handle !== null) {
            try {
                $handle = Resolver::handleToDid($handle, false) === $did ? $handle : null;
            } catch (Throwable) {
                $handle = null;
            }
        }

        app(Profiles::class)->forget($did);

        $identity = Identity::query()->find($did);
        $changed = [];

        if ($identity !== null) {
            if ($pds !== $identity->pds) {
                $changed['pds'] = [$identity->pds, $pds];
            }

            // An unverified handle leaves the stored one alone: Tap may know better.
            if ($handle !== null && $handle !== $identity->handle) {
                $changed['handle'] = [$identity->handle, $handle];
            }

            $identity->forceFill([
                'pds' => $pds,
                'pds_resolved_at' => Carbon::now('UTC'),
                'updated_at' => Carbon::now('UTC'),
                ...($handle === null ? [] : ['handle' => $handle]),
            ])->save();
        }

        return ['handle' => $handle, 'pds' => $pds, 'changed' => $changed];
    }
}
