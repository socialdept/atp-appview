<?php

namespace SocialDept\AtpAppview\Standing\Contracts;

/**
 * Where a verifier's trusted issuers come from, when a fixed list in config
 * will not do: a table operators edit, a list a community publishes, or one
 * refreshed from elsewhere. Named among a RecordVerifier's issuers.
 */
interface TrustedIssuers
{
    /**
     * @return array<int, string> DIDs
     */
    public function dids(): array;
}
