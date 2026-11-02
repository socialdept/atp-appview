<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Claims;

use SocialDept\AtpAppview\Claims\Contracts\ClaimChecker;
use SocialDept\AtpAppview\Claims\Verdict;
use SocialDept\AtpAppview\Models\Record;

/**
 * Claims a record's url, answering with the verdicts a test sets and
 * recording what it was asked to check.
 */
class SiteChecker implements ClaimChecker
{
    /** @var array<string, Verdict> */
    public static array $verdicts = [];

    /** @var array<int, string> */
    public static array $checked = [];

    public function claim(Record $record): ?array
    {
        $url = $record->record['url'] ?? null;

        return is_string($url) ? ['url' => $url] : null;
    }

    public function host(Record $record, array $claim): string
    {
        return (string) parse_url($claim['url'], PHP_URL_HOST);
    }

    public function check(array $records): array
    {
        $verdicts = [];

        foreach ($records as $record) {
            self::$checked[] = $record->uri;

            if (isset(self::$verdicts[$record->uri])) {
                $verdicts[$record->uri] = self::$verdicts[$record->uri];
            }
        }

        return $verdicts;
    }
}
