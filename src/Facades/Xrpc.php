<?php

namespace SocialDept\AtpAppview\Facades;

use Illuminate\Support\Facades\Facade;
use SocialDept\AtpAppview\Xrpc\XrpcClient;

/**
 * @method static XrpcClient as(\SocialDept\AtpAppview\Auth\Viewer|\Illuminate\Contracts\Auth\Authenticatable|string|null $who)
 * @method static XrpcClient service(?string $service)
 * @method static array query(string $nsid, array $params = [])
 * @method static array procedure(string $nsid, array $input = [])
 *
 * @see XrpcClient
 */
class Xrpc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return XrpcClient::class;
    }
}
