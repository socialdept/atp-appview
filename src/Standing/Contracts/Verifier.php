<?php

namespace SocialDept\AtpAppview\Standing\Contracts;

use SocialDept\AtpAppview\Standing\Verification;

/**
 * Decides whether accounts are verified, and says why.
 */
interface Verifier
{
    /**
     * @param  array<int, string>  $dids
     * @return array<string, Verification> By DID
     */
    public function verify(array $dids): array;

    /**
     * Whether an account issues verifications this verifier trusts, as
     * Bluesky's trustedVerifierStatus says.
     */
    public function trusts(string $did): bool;
}
