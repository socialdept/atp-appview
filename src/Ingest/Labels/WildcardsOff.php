<?php

namespace SocialDept\AtpAppview\Ingest\Labels;

use RuntimeException;

/**
 * The labeler does not answer queryLabels for wildcard patterns, as Ozone
 * does not by default.
 */
class WildcardsOff extends RuntimeException
{
}
