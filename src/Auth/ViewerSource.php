<?php

namespace SocialDept\AtpAppview\Auth;

/**
 * How a viewer proved who they are.
 */
enum ViewerSource: string
{
    /**
     * The application's own session cookie, for a user whose OAuth session
     * the server may hold (backend mode).
     */
    case Session = 'session';

    /**
     * A service auth JWT, signed with the caller's repo key. It arrives when a
     * PDS proxies a request (frontend mode), when a browser holds a token
     * from getServiceAuth, or when another service calls.
     */
    case ServiceAuth = 'service_auth';
}
