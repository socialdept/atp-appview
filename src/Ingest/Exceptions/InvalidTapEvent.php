<?php

namespace SocialDept\AtpAppview\Ingest\Exceptions;

use RuntimeException;

class InvalidTapEvent extends RuntimeException
{
    public function __construct(string $detail)
    {
        parent::__construct("Invalid Tap event: {$detail}.");
    }
}
