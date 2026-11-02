<?php

namespace SocialDept\AtpAppview\Claims\Contracts;

use SocialDept\AtpAppview\Claims\Verdict;
use SocialDept\AtpAppview\Models\Record;

/**
 * Checks the claim records of one collection make, such as owning the
 * website they name. Listed per collection in atp-appview.claims.
 */
interface ClaimChecker
{
    /**
     * The fields the record's verdict depends on, or null when it makes no
     * claim. A change to any of them sends the record back to pending.
     *
     * @return array<string, mixed>|null
     */
    public function claim(Record $record): ?array;

    /**
     * Where checking this claim sends requests, so checks are grouped and
     * rate limited by host.
     *
     * @param  array<string, mixed>  $claim
     */
    public function host(Record $record, array $claim): string;

    /**
     * A verdict per record, by URI. A record left out is unknown.
     *
     * @param  array<int, Record>  $records
     * @return array<string, Verdict>
     */
    public function check(array $records): array;
}
