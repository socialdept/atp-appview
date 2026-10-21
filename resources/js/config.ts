/**
 * What the browser library needs from the app: the package's routes and
 * the settings for starting a server session.
 *
 * Pass the routes as Wayfinder helpers, or anything with a `url()` method,
 * so no path is written by hand:
 *
 *     import * as oauth from '@/routes/atp/oauth';
 *     import * as session from '@/routes/atp/session';
 *
 *     configureAtproto({
 *         routes: {
 *             prepare: oauth.prepare,
 *             clientAssertion: oauth.clientAssertion,
 *             flow: oauth.flow,
 *             account: oauth.account,
 *             startSession: session.store,
 *             endSession: session.destroy,
 *         },
 *         ...page.props.atproto,
 *     });
 */
export interface AtprotoRoute {
    url: (options?: { query?: Record<string, string> }) => string;
}

export interface AtprotoConfig {
    routes: {
        prepare: AtprotoRoute;
        clientAssertion: AtprotoRoute;
        flow: AtprotoRoute;
        account: AtprotoRoute;
        startSession: AtprotoRoute;
        endSession: AtprotoRoute;
    };
    // From FrontendAuth::browserConfig() on the server.
    sessionMethod: string | null;
    audience: string | null;
    // Where this AppView's XRPC routes are, /xrpc by default.
    xrpcPrefix?: string;
    // The procedure that indexes a record this browser wrote, if any.
    syncRecord?: string | null;
    // Whether a PDS can reach this AppView. False keeps calls same origin.
    proxy?: boolean;
    // The procedure the browser joins its private channel through, when the
    // app has the realtime feature. Null otherwise.
    realtime?: { authorize: string } | null;
    // Prefixes the browser storage the library uses, so two apps on one
    // origin keep their sessions apart.
    namespace?: string;
}

let current: AtprotoConfig | null = null;

export function configureAtproto(config: AtprotoConfig): void {
    current = config;
}

export function config(): AtprotoConfig {
    if (!current) {
        throw new Error('Call configureAtproto() before signing in.');
    }

    return current;
}

/**
 * The configuration, or null before configureAtproto(), for helpers that
 * work without it.
 */
export function configured(): AtprotoConfig | null {
    return current;
}

/**
 * A storage name in this app's namespace. Readable before configuration,
 * so a page can look at stored sessions straight away.
 */
export function storageName(name: string): string {
    return `${current?.namespace ?? 'atp'}_${name}`;
}
