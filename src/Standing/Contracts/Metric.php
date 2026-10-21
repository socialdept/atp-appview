<?php

namespace SocialDept\AtpAppview\Standing\Contracts;

/**
 * A number about an account or a record, from wherever it can be counted.
 */
interface Metric
{
    /**
     * What it measures: Standing::ACCOUNT or Standing::RECORD.
     */
    public function subject(): string;

    /**
     * @param  array<int, string>  $subjects  DIDs or AT-URIs
     * @return array<string, int|float|null> By subject. Null when it could not be measured, to try again later.
     */
    public function measure(array $subjects): array;
}
