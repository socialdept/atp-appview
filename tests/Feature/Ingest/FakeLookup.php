<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use RuntimeException;
use SocialDept\AtpAppview\Ingest\Labels\LabelerLookup;

/**
 * Labeler services, keys and declarations a test sets, counting lookups.
 */
class FakeLookup extends LabelerLookup
{
    /** @var array<string, string> */
    public array $keys = [];

    /** @var array<string, string> */
    public array $endpoints = [];

    /** @var array<string, array<string, mixed>|null> */
    public array $declarations = [];

    public int $calls = 0;

    public bool $fresh = false;

    public function service(string $did, bool $fresh = false): array
    {
        $this->calls++;
        $this->fresh = $fresh;

        return [
            'endpoint' => $this->endpoints[$did] ?? 'https://labeler.example',
            'key' => $this->keys[$did] ?? throw new RuntimeException("{$did} publishes no label key."),
        ];
    }

    public function declaration(string $did): ?array
    {
        return $this->declarations[$did] ?? null;
    }
}
