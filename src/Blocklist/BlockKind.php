<?php

namespace SocialDept\AtpAppview\Blocklist;

enum BlockKind: string
{
    /** Every record in one repo. */
    case Did = 'did';

    /** Every record in repos hosted on a matching PDS. */
    case Host = 'host';
}
