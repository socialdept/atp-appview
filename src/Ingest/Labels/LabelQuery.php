<?php

namespace SocialDept\AtpAppview\Ingest\Labels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * A labeler's com.atproto.label.queryLabels, every page of it, paced per
 * labeler so a burst of new accounts is a steady trickle to it.
 */
class LabelQuery
{
    /** Patterns in one request. */
    public const PATTERNS = 100;

    protected const PAGE = 250;

    public function __construct(
        protected int $perSecond = 4,
    ) {
    }

    /**
     * The labeler's labels matching any of the patterns, as it signed them,
     * with sig as raw bytes.
     *
     * @param  array<int, string>  $patterns  At most PATTERNS: DIDs, URIs, or at://did/* where the labeler allows it
     * @return array<int, array<string, mixed>>
     *
     * @throws WildcardsOff when the labeler refuses a wildcard pattern
     * @throws RuntimeException when it cannot be read
     */
    public function labels(string $endpoint, string $labeler, array $patterns): array
    {
        $labels = [];
        $cursor = null;

        do {
            $this->pace($labeler);

            $query = [...array_map(fn ($pattern) => 'uriPatterns='.rawurlencode($pattern), $patterns), 'sources='.rawurlencode($labeler), 'limit='.self::PAGE];

            if ($cursor !== null) {
                $query[] = 'cursor='.rawurlencode($cursor);
            }

            $response = Http::timeout(30)->acceptJson()->get(rtrim($endpoint, '/').'/xrpc/com.atproto.label.queryLabels?'.implode('&', $query));

            if ($response->status() === 400 && str_contains(strtolower($response->body()), 'wildcard')) {
                throw new WildcardsOff("{$labeler} does not take wildcard queries.");
            }

            if (! $response->successful()) {
                throw new RuntimeException("queryLabels answered {$response->status()}: ".substr(trim($response->body()), 0, 200));
            }

            $page = array_values(array_filter((array) $response->json('labels'), 'is_array'));

            foreach ($page as $label) {
                $labels[] = self::signed($label);
            }

            $next = $response->json('cursor');
            $done = ! is_string($next) || $next === '' || $next === $cursor || $page === [];
            $cursor = $next;
        } while (! $done);

        return $labels;
    }

    /**
     * A label from JSON, where bytes are {"$bytes": base64}, as the labeler
     * signed it.
     *
     * @param  array<string, mixed>  $label
     * @return array<string, mixed>
     */
    protected static function signed(array $label): array
    {
        if (is_array($label['sig'] ?? null)) {
            $label['sig'] = base64_decode((string) ($label['sig']['$bytes'] ?? ''));
        }

        return $label;
    }

    protected function pace(string $labeler): void
    {
        while (! RateLimiter::attempt('atp-appview:labels:query:'.$labeler, max(1, $this->perSecond), fn () => true, 1)) {
            usleep(100_000);
        }
    }
}
