<?php

namespace SocialDept\AtpAppview\Standing\Metrics;

use SocialDept\AtpAppview\Standing\Contracts\Metric;
use SocialDept\AtpAppview\Standing\Standing;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Records of a collection in an account's own repo, counted from its PDS a
 * hundred at a time, up to a cap: the only way to a following count, since
 * nothing indexes outgoing links. Costly for large accounts, so capped.
 */
class RepoCount implements Metric
{
    public const PAGE = 100;

    public function __construct(
        protected string $collection,
        protected int $cap = 5000,
    ) {
    }

    public function subject(): string
    {
        return Standing::ACCOUNT;
    }

    public function measure(array $subjects): array
    {
        $counts = [];

        foreach ($subjects as $did) {
            try {
                $pds = Resolver::resolvePds($did);

                if ($pds === null) {
                    $counts[$did] = null;

                    continue;
                }

                $repo = Atp::public($pds)->atproto->repo;
                $count = 0;
                $cursor = null;

                do {
                    $page = $repo->listRecords($did, $this->collection, self::PAGE, $cursor);
                    $count += $page->records->count();
                    $cursor = $page->cursor;
                } while ($cursor !== null && $page->records->isNotEmpty() && $count < $this->cap);

                $counts[$did] = min($count, $this->cap);
            } catch (Throwable) {
                $counts[$did] = null;
            }
        }

        return $counts;
    }
}
