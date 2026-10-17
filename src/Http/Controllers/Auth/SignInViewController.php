<?php

namespace SocialDept\AtpAppview\Http\Controllers\Auth;

use Illuminate\Container\Container;
use Illuminate\Routing\Controller;
use SocialDept\AtpAppview\Auth\FrontendAuth;

/**
 * The login page and the page the callback finishes on, rendered by the
 * callbacks an app gives FrontendAuth, so the package needs no view layer.
 */
class SignInViewController extends Controller
{
    public function login(Container $container): mixed
    {
        return $container->call(FrontendAuth::view('login') ?? abort(404));
    }

    public function complete(Container $container): mixed
    {
        return $container->call(FrontendAuth::view('complete') ?? abort(404));
    }
}
