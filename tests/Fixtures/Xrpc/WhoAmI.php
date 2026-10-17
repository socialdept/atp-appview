<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Xrpc;

use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpAppview\Xrpc\Auth;

/**
 * No lexicon, so the attribute names the type. Receives the viewer by type.
 */
#[Xrpc('com.example.whoAmI', auth: Auth::Required, type: 'query')]
class WhoAmI
{
    public function __invoke(Viewer $viewer): array
    {
        return ['did' => $viewer->did];
    }
}
