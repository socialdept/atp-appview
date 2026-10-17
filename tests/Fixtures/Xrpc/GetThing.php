<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Xrpc;

use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

#[Xrpc('com.example.getThing')]
class GetThing
{
    public function __invoke(XrpcRequest $request): array
    {
        return ['params' => $request->params(), 'viewer' => $request->viewer()?->did];
    }
}
