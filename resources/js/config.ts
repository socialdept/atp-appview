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
 * A storage name in this app's namespace. Readable before configuration,
 * so a page can look at stored sessions straight away.
 */
export function storageName(name: string): string {
    return `${current?.namespace ?? 'atp'}_${name}`;
}
