import { configured } from './config';
import { appFetch } from './http';
import { serverDid } from './reconnect';
import { currentSession, pdsFetch } from './session';

/**
 * Calling XRPC methods the same way whoever answers them. The helper picks
 * how to authenticate:
 *
 * - This AppView, with a server session: same origin, with the cookie and
 *   the CSRF header. The cheapest path.
 * - This AppView, with only the browser's tokens: through the account's PDS,
 *   with atproto-proxy naming the AppView's service (`audience`).
 * - Another service: through the account's PDS, with atproto-proxy naming it.
 * - Nobody signed in: directly, unauthenticated, for public methods.
 *
 *     const page = await xrpc.query('com.example.listThings', { limit: 30 });
 *     await xrpc.procedure('com.example.updateSeen', {});
 *     await xrpc.query('app.bsky.feed.getTimeline', {}, {
 *         service: 'did:web:api.bsky.app#bsky_appview',
 *     });
 *
 * Errors are thrown as XrpcError with the method's error name. A call made
 * through the PDS can throw SessionExpiredError: wrap one the user started
 * in withSession() to offer reconnecting, and leave background calls bare.
 */
export class XrpcError extends Error {
    constructor(
        public readonly error: string,
        message: string,
        public readonly status: number,
    ) {
        super(message);
        this.name = 'XrpcError';
    }
}

export interface XrpcOptions {
    // The service to call, such as did:web:api.bsky.app#bsky_appview.
    // Left out, or this AppView's own audience, calls this AppView.
    service?: string | null;
    headers?: HeadersInit;
    signal?: AbortSignal;
}

type Params = Record<
    string,
    string | number | boolean | null | undefined | Array<string | number>
>;

export async function query<T = Record<string, unknown>>(
    nsid: string,
    params: Params = {},
    options: XrpcOptions = {},
): Promise<T> {
    return call<T>('GET', nsid, params, undefined, options);
}

export async function procedure<T = Record<string, unknown>>(
    nsid: string,
    input: Record<string, unknown> = {},
    options: XrpcOptions = {},
): Promise<T> {
    return call<T>('POST', nsid, {}, input, options);
}

export const xrpc = { query, procedure };

/**
 * Whether a service is this AppView: none named, or its own audience, with
 * or without the service id.
 */
export function isLocal(service: string | null | undefined): boolean {
    if (!service) {
        return true;
    }

    const audience = configured()?.audience ?? null;

    return (
        audience !== null &&
        (service === audience || audience.split('#')[0] === service)
    );
}

/**
 * Parameters as XRPC sends them: an array repeats its name per item.
 */
export function queryString(params: Params): string {
    const search = new URLSearchParams();

    for (const [name, value] of Object.entries(params)) {
        for (const item of Array.isArray(value) ? value : [value]) {
            if (item !== null && item !== undefined) {
                search.append(name, String(item));
            }
        }
    }

    return search.toString();
}

async function call<T>(
    method: 'GET' | 'POST',
    nsid: string,
    params: Params,
    input: Record<string, unknown> | undefined,
    options: XrpcOptions,
): Promise<T> {
    const search = queryString(params);
    const path = `/xrpc/${nsid}${search ? `?${search}` : ''}`;
    const init: RequestInit = {
        method,
        headers: new Headers(options.headers),
        signal: options.signal,
        body: input === undefined ? undefined : JSON.stringify(input),
    };

    if (input !== undefined) {
        (init.headers as Headers).set('Content-Type', 'application/json');
    }

    const local = isLocal(options.service);
    const browser = currentSession();

    if (local && serverDid()) {
        const response = await appFetch(localUrl(nsid, search), init);

        // The cookie ran out while the browser can still act: go through
        // the PDS rather than fail.
        if (response.status !== 401 || !browser || !proxyTarget(options)) {
            return read<T>(response);
        }
    }

    const target = proxyTarget(options);

    if (browser && target) {
        const headers = new Headers(init.headers);
        headers.set('atproto-proxy', target);

        return read<T>(
            await pdsFetch(browser.did, path, { ...init, headers }),
        );
    }

    if (local) {
        return read<T>(await appFetch(localUrl(nsid, search), init));
    }

    return read<T>(
        await fetch(`${await endpointOf(options.service!)}${path}`, init),
    );
}

function localUrl(nsid: string, search: string): string {
    const prefix = configured()?.xrpcPrefix ?? '/xrpc';

    return `${prefix.replace(/\/$/, '')}/${nsid}${search ? `?${search}` : ''}`;
}

// What atproto-proxy names: the service asked for, or this AppView.
function proxyTarget(options: XrpcOptions): string | null {
    return isLocal(options.service)
        ? (configured()?.audience ?? null)
        : (options.service ?? null);
}

async function read<T>(response: Response): Promise<T> {
    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new XrpcError(
            typeof body?.error === 'string' ? body.error : 'UpstreamFailure',
            typeof body?.message === 'string'
                ? body.message
                : `The call failed with ${response.status}.`,
            response.status,
        );
    }

    return (body ?? {}) as T;
}

const endpoints = new Map<string, string>();

// did:web:example.com reads /.well-known/did.json, did:web:example.com:u
// reads /u/did.json.
function webDocument(did: string): string {
    const [host, ...path] = did.slice(8).split(':').map(decodeURIComponent);

    return path.length
        ? `https://${host}/${path.join('/')}/did.json`
        : `https://${host}/.well-known/did.json`;
}

/**
 * Where a service is reached, from its DID document: did:web from its
 * well-known path, did:plc from the PLC directory.
 */
async function endpointOf(service: string): Promise<string> {
    const cached = endpoints.get(service);

    if (cached) {
        return cached;
    }

    const [did, id = ''] = service.split('#');
    const url = did.startsWith('did:web:')
        ? webDocument(did)
        : `https://plc.directory/${did}`;
    const document = await fetch(url)
        .then((response) => (response.ok ? response.json() : null))
        .catch(() => null);
    const entry = (document?.service ?? []).find(
        (item: { id?: string }) =>
            item.id === `#${id}` || item.id === `${did}#${id}`,
    );

    if (typeof entry?.serviceEndpoint !== 'string') {
        throw new XrpcError(
            'ServiceNotFound',
            `${did} publishes no #${id} service.`,
            502,
        );
    }

    const endpoint = entry.serviceEndpoint.replace(/\/$/, '');
    endpoints.set(service, endpoint);

    return endpoint;
}
