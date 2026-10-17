<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Xrpc;

use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

#[Xrpc('com.example.createThing', auth: Auth::None)]
class CreateThing
{
    public function __invoke(XrpcRequest $request): array
    {
        if ($request->input()['text'] === 'taken') {
            throw new XrpcException('AlreadyExists', 'That thing already exists.', 409);
        }

        return ['created' => $request->input()['text']];
    }
}
