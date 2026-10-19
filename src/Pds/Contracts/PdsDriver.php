<?php

namespace SocialDept\AtpAppview\Pds\Contracts;

/**
 * Takes accounts down on a PDS the operator runs, and restores them.
 */
interface PdsDriver
{
    /**
     * @param  string  $ref  A reference for the takedown, kept by the PDS
     */
    public function takedown(string $did, string $ref): void;

    public function restore(string $did): void;

    /**
     * Whether the account is taken down on the PDS now.
     */
    public function takenDown(string $did): bool;
}
