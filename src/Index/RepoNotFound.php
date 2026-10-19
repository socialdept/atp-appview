<?php

namespace SocialDept\AtpAppview\Index;

use RuntimeException;

/**
 * A DID whose document names no PDS, so its records cannot be read.
 */
class RepoNotFound extends RuntimeException
{
}
