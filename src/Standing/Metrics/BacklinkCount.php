<?php

namespace SocialDept\AtpAppview\Standing\Metrics;

use SocialDept\AtpAppview\Standing\Contracts\Metric;
use SocialDept\AtpSupport\Facades\Microcosm;
use Throwable;

/**
 * Records across the network that point at a subject through a field, as
 * Constellation counts them: an account's followers, a post's likes. Covers
 * what the app does not ingest.
 */
class BacklinkCount implements Metric
{
    /**
     * @param  string  $link  collection:path, such as app.bsky.graph.follow:subject
     */
    public function __construct(
        protected string $subject,
        protected string $link,
    ) {
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function measure(array $subjects): array
    {
        $counts = [];

        foreach ($subjects as $subject) {
            try {
                $counts[$subject] = Microcosm::constellation()->getBacklinksCount($subject, $this->link);
            } catch (Throwable) {
                // Constellation is down or refused: unknown, tried again later.
                $counts[$subject] = null;
            }
        }

        return $counts;
    }
}
