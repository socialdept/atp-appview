import { beforeEach, describe, expect, it, vi } from 'vitest';

let server: string | null = null;
let browser: { did: string } | null = null;
const appFetch = vi.fn();
const pdsFetch = vi.fn();

vi.mock('../../resources/js/http', () => ({
    appFetch: (...args: unknown[]) => appFetch(...args),
}));

vi.mock('../../resources/js/reconnect', () => ({
    serverDid: () => server,
}));

vi.mock('../../resources/js/session', () => ({
    currentSession: () => browser,
    pdsFetch: (...args: unknown[]) => pdsFetch(...args),
}));

const { configureAtproto } = await import('../../resources/js/config');
const { xrpc, XrpcError, queryString, isLocal } = await import(
    '../../resources/js/xrpc'
);

const AUDIENCE = 'did:web:appview.example#atproto_appview';
const BSKY = 'did:web:api.bsky.app#bsky_appview';
const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status });

function header(init: RequestInit, name: string): string | null {
    return new Headers(init.headers).get(name);
}

beforeEach(() => {
    server = null;
    browser = null;
    appFetch.mockReset();
    pdsFetch.mockReset();
    configureAtproto({
        routes: {} as never,
        sessionMethod: null,
        audience: AUDIENCE,
        xrpcPrefix: '/xrpc',
    });
});

describe('xrpc', () => {
    it('calls this AppView on the same origin when the server has a session', async () => {
        server = 'did:plc:alice';
        browser = { did: 'did:plc:alice' };
        appFetch.mockResolvedValue(json({ count: 3 }));

        const result = await xrpc.query('com.example.getUnreadCount', {
            limit: 2,
        });

        expect(result).toEqual({ count: 3 });
        expect(appFetch).toHaveBeenCalledWith(
            '/xrpc/com.example.getUnreadCount?limit=2',
            expect.objectContaining({ method: 'GET' }),
        );
        expect(pdsFetch).not.toHaveBeenCalled();
    });

    it('goes through the PDS to this AppView when the cookie has run out', async () => {
        server = 'did:plc:alice';
        browser = { did: 'did:plc:alice' };
        appFetch.mockResolvedValue(
            json({ error: 'AuthenticationRequired' }, 401),
        );
        pdsFetch.mockResolvedValue(json({ count: 1 }));

        expect(await xrpc.query('com.example.getUnreadCount')).toEqual({
            count: 1,
        });

        const [did, path, init] = pdsFetch.mock.calls[0];
        expect([did, path]).toEqual([
            'did:plc:alice',
            '/xrpc/com.example.getUnreadCount',
        ]);
        expect(header(init, 'atproto-proxy')).toBe(AUDIENCE);
    });

    it('goes through the PDS when only the browser holds a session', async () => {
        browser = { did: 'did:plc:alice' };
        pdsFetch.mockResolvedValue(json({ seenAt: 'now' }));

        await xrpc.procedure('com.example.updateSeen', { seenAt: 'now' });

        const [, path, init] = pdsFetch.mock.calls[0];
        expect(path).toBe('/xrpc/com.example.updateSeen');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"seenAt":"now"}');
        expect(header(init, 'content-type')).toBe('application/json');
        expect(header(init, 'atproto-proxy')).toBe(AUDIENCE);
        expect(appFetch).not.toHaveBeenCalled();
    });

    it('stays same origin when no PDS can reach this AppView', async () => {
        browser = { did: 'did:plc:alice' };
        configureAtproto({
            routes: {} as never,
            sessionMethod: null,
            audience: AUDIENCE,
            proxy: false,
        });
        appFetch.mockResolvedValue(json({ count: 2 }));

        expect(await xrpc.query('com.example.getUnreadCount')).toEqual({
            count: 2,
        });
        expect(pdsFetch).not.toHaveBeenCalled();
    });

    it('calls another service through the PDS even with a server session', async () => {
        server = 'did:plc:alice';
        browser = { did: 'did:plc:alice' };
        pdsFetch.mockResolvedValue(json({ feed: [] }));

        await xrpc.query(
            'app.bsky.feed.getTimeline',
            { langs: ['en', 'pt'] },
            { service: BSKY },
        );

        const [, path, init] = pdsFetch.mock.calls[0];
        expect(path).toBe('/xrpc/app.bsky.feed.getTimeline?langs=en&langs=pt');
        expect(header(init, 'atproto-proxy')).toBe(BSKY);
        expect(appFetch).not.toHaveBeenCalled();
    });

    it('calls this AppView anonymously when nobody is signed in', async () => {
        appFetch.mockResolvedValue(json({ records: [] }));

        await xrpc.query('com.example.listThings');

        expect(appFetch).toHaveBeenCalledWith(
            '/xrpc/com.example.listThings',
            expect.anything(),
        );
    });

    it('calls another service directly, from its DID document, when nobody is signed in', async () => {
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(
                json({
                    id: 'did:web:api.bsky.app',
                    service: [
                        {
                            id: '#bsky_appview',
                            serviceEndpoint: 'https://api.bsky.app/',
                        },
                    ],
                }),
            )
            .mockResolvedValueOnce(json({ ok: true }));

        expect(
            await xrpc.query(
                'app.bsky.actor.getProfile',
                { actor: 'alice.test' },
                { service: BSKY },
            ),
        ).toEqual({ ok: true });
        expect(fetchMock.mock.calls.map((call) => call[0])).toEqual([
            'https://api.bsky.app/.well-known/did.json',
            'https://api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=alice.test',
        ]);
        fetchMock.mockRestore();
    });

    it('throws the method error by name', async () => {
        appFetch.mockResolvedValue(
            json({ error: 'InvalidRequest', message: 'Bad limit.' }, 400),
        );

        const error = await xrpc
            .query('com.example.listThings')
            .catch((e: unknown) => e);

        expect(error).toBeInstanceOf(XrpcError);
        expect(error).toMatchObject({
            error: 'InvalidRequest',
            message: 'Bad limit.',
            status: 400,
        });
    });

    it('knows its own service, with or without the service id', () => {
        expect(isLocal(undefined)).toBe(true);
        expect(isLocal(AUDIENCE)).toBe(true);
        expect(isLocal('did:web:appview.example')).toBe(true);
        expect(isLocal(BSKY)).toBe(false);
        expect(queryString({ a: [1, 2], b: null, c: true })).toBe(
            'a=1&a=2&c=true',
        );
    });
});
