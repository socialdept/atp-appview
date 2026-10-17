<?php

namespace SocialDept\AtpAppview\Xrpc;

/**
 * Whether an XRPC method needs a viewer.
 */
enum Auth: string
{
    /** Refuse anonymous requests with AuthenticationRequired. */
    case Required = 'required';

    /** Resolve the viewer when there is one, and serve anonymous requests too. */
    case Optional = 'optional';

    /** Skip the guard entirely, for public methods that never look at the viewer. */
    case None = 'none';
}
