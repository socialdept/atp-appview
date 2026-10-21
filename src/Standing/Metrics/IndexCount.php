<?php

namespace SocialDept\AtpAppview\Standing\Metrics;

use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Standing\Contracts\Metric;

/**
 * Records the index holds that point at a subject through a field, such as
 * the likes of a post or the follows of an account, from the counts the
 * index keeps. Free and current, and only as complete as what the app
 * ingests.
 */
class IndexCount implements Metric
{
    public function __construct(
        protected string $subject,
        protected string $collection,
        protected string $path,
    ) {
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function measure(array $subjects): array
    {
        $values = app(RecordValues::class);
        $field = $values->field($this->collection, $this->path);

        if ($field === null || $subjects === []) {
            return array_fill_keys($subjects, null);
        }

        $key = $values->keyId($field);
        $hashes = array_combine($subjects, array_map(fn (string $subject) => ValueHash::of($subject), $subjects));
        $totals = $values->totals([$key], array_values($hashes));

        return array_map(fn (int $hash) => $totals["{$key}#{$hash}"] ?? 0, $hashes);
    }
}
