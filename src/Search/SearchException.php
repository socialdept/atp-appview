<?php

namespace SocialDept\AtpAppview\Search;

use InvalidArgumentException;

/**
 * A search the index cannot answer, served as BadQueryString.
 */
class SearchException extends InvalidArgumentException
{
}
