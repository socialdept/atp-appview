<?php

namespace SocialDept\AtpAppview\Ingest;

use RuntimeException;

/**
 * The Tap being talked to lacks an admin route this needs.
 */
class TapFeatureUnavailableException extends RuntimeException
{
    public static function resync(): self
    {
        return new self('This Tap cannot resync tracked repos: it has no POST /resync/:did route. Tab (tangled.org/pds.dad/tab) adds it.');
    }
}
