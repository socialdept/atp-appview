<?php

namespace SocialDept\AtpAppview\Feeds;

/**
 * One item of a skeleton: any record by its AT-URI, why it is there, and a
 * context handed back with the viewer's interactions on it.
 */
final class FeedItem
{
    /**
     * @param  array<string, mixed>|null  $reason  A reason in the defs lexicon, such as a repost or a pin
     */
    public function __construct(
        public readonly string $uri,
        public readonly ?array $reason = null,
        public readonly ?string $feedContext = null,
    ) {
    }

    /**
     * An item shown because someone reposted it, by the repost's URI.
     */
    public static function repost(string $uri, string $repost, ?string $feedContext = null): self
    {
        return new self($uri, ['$type' => Feeds::lexicon('defs').'#skeletonReasonRepost', 'repost' => $repost], $feedContext);
    }

    /**
     * An item pinned to the top.
     */
    public static function pin(string $uri, ?string $feedContext = null): self
    {
        return new self($uri, ['$type' => Feeds::lexicon('defs').'#skeletonReasonPin'], $feedContext);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'post' => $this->uri,
            'reason' => $this->reason,
            'feedContext' => $this->feedContext === null ? null : mb_substr($this->feedContext, 0, 2000),
        ], fn ($value) => $value !== null);
    }
}
