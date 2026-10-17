import { beforeEach, describe, expect, it, vi } from 'vitest';

const keys = new Map<string, object>();
const appResponses = new Map<string, () => Response>();
const tokenResponse = vi.fn<() => Response>();
const vouch = vi.fn(async (..._args: unknown[]) => undefined);

vi.mock('../../resources/js/dpop-keys', () => ({
    getDpopKeyHandle: async (id: string) => keys.get(id) ?? null,
    deleteKeyPair: async (id: string) => void keys.delete(id),
    reKeyPair: async (from: string, to: string) => {
        keys.set(to, keys.get(from)!);
        keys.delete(from);
    },
    generateKeyPair: async () => ({
        privateKey: 'k',
        publicKey: 'p',
        publicJwk: {},
    }),
    storeKeyPair: async (id: string, pair: object) => void keys.set(id, pair),
}));

vi.mock('../../resources/js/dpop', () => ({
    dpopFormPost: vi.fn(async () => tokenResponse()),
    dpopFetch: vi.fn(),
}));

vi.mock('../../resources/js/http', async () => {
    class AssertionRefusedError extends Error {}

    return {
        AssertionRefusedError,
        clientAssertion: async () => ({ client_assertion: 'signed' }),
        appFetch: vi.fn(async (url: string) =>
            appResponses.get(url.split('?')[0])!(),
        ),
        errorMessage: async (_response: Response, fallback: string) => fallback,
    };
});

vi.mock('../../resources/js/server-session', () => ({
    vouchFor: (...args: unknown[]) => vouch(...args),
}));

const { configureAtproto } = await import('../../resources/js/config');
const { handleCallback, signIn } = await import('../../resources/js/oauth');
const session = await import('../../resources/js/session');

const route = (path: string) => ({
    url: (options?: { query?: Record<string, string> }) =>
        options?.query ? `${path}?${new URLSearchParams(options.query)}` : path,
});

configureAtproto({
    routes: {
        prepare: route('/oauth/prepare'),
        clientAssertion: route('/oauth/client-assertion'),
        flow: route('/oauth/flow'),
        account: route('/oauth/account'),
        startSession: route('/session'),
        endSession: route('/session'),
    },
    sessionMethod: 'com.example.createSession',
    audience: 'did:web:app.example',
});

const ALICE = 'did:plc:alice';

const json =
    (body: unknown, status = 200) =>
    () =>
        new Response(JSON.stringify(body), { status });

function flow(overrides: Record<string, unknown> = {}) {
    return {
        code: 'code',
        state: 'state-1',
        codeVerifier: 'verifier',
        issuer: 'https://auth.example',
        tokenEndpoint: 'https://auth.example/oauth/token',
        redirectUri: 'https://app.example/oauth/callback',
        clientId: 'client',
        handle: null,
        did: null,
        ...overrides,
    };
}

beforeEach(() => {
    const data = new Map<string, string>();
    vi.stubGlobal('localStorage', {
        getItem: (k: string) => data.get(k) ?? null,
        setItem: (k: string, v: string) => void data.set(k, String(v)),
        removeItem: (k: string) => void data.delete(k),
    });
    vi.stubGlobal('window', {
        location: {
            origin: 'https://app.example',
            href: 'https://app.example/',
        },
        close: vi.fn(),
    });
    keys.clear();
    appResponses.clear();
    vouch.mockClear();
    keys.set('oauth_state:state-1', { privateKey: 'k', publicJwk: {} });
    tokenResponse.mockReturnValue(
        new Response(
            JSON.stringify({
                sub: ALICE,
                access_token: 'a',
                refresh_token: 'r',
                expires_in: 3600,
                scope: 'atproto',
            }),
        ),
    );
    appResponses.set(
        '/oauth/account',
        json({
            did: ALICE,
            pds: 'https://pds.example',
            issuer: 'https://auth.example',
            revocationEndpoint: null,
            handle: 'alice.test',
        }),
    );
});

describe('handleCallback', () => {
    it('stores the session, moves the key to the DID and starts a server session', async () => {
        appResponses.set('/oauth/flow', json(flow()));

        expect(await handleCallback()).toEqual({ returnTo: null });

        expect(session.currentSession()).toMatchObject({
            did: ALICE,
            handle: 'alice.test',
            tokens: { accessToken: 'a' },
        });
        expect(keys.has(ALICE)).toBe(true);
        expect(keys.has('oauth_state:state-1')).toBe(false);
        expect(vouch).toHaveBeenCalledOnce();
    });

    it('refuses a sign-in back into a known account that lands on another one', async () => {
        appResponses.set('/oauth/flow', json(flow({ did: 'did:plc:bob' })));

        await expect(handleCallback()).rejects.toThrow(
            'You signed in with a different account. Try again.',
        );

        expect(session.currentSession()).toBeNull();
        expect(vouch).not.toHaveBeenCalled();
    });

    it('refuses an account whose PDS uses another authorization server', async () => {
        appResponses.set(
            '/oauth/flow',
            json(flow({ issuer: 'https://evil.example' })),
        );

        await expect(handleCallback()).rejects.toThrow(
            'That server may not sign in this account.',
        );
        expect(session.currentSession()).toBeNull();
    });

    it('refuses a handle sign-in that lands on another handle', async () => {
        appResponses.set('/oauth/flow', json(flow({ handle: 'bob.test' })));

        await expect(handleCallback()).rejects.toThrow(
            'Signed in as alice.test, not bob.test.',
        );
    });

    it('says so when the flow has expired', async () => {
        appResponses.set('/oauth/flow', json({ message: 'gone' }, 410));

        await expect(handleCallback()).rejects.toThrow(
            'That sign-in has expired. Try again.',
        );
    });
});

describe('signIn', () => {
    it('only returns to a URL on this site', async () => {
        appResponses.set(
            '/oauth/prepare',
            json({
                state: 'state-2',
                issuer: 'https://auth.example',
                parEndpoint: 'https://auth.example/par',
                authorizationEndpoint: 'https://auth.example/authorize',
                clientId: 'client',
            }),
        );
        tokenResponse.mockReturnValue(
            new Response(JSON.stringify({ request_uri: 'urn:1' })),
        );

        await signIn({ did: ALICE }, 'https://evil.example/');
        appResponses.set('/oauth/flow', json(flow({ state: 'state-2' })));
        keys.set('oauth_state:state-2', { privateKey: 'k', publicJwk: {} });
        tokenResponse.mockReturnValue(
            new Response(
                JSON.stringify({
                    sub: ALICE,
                    access_token: 'a',
                    refresh_token: 'r',
                    expires_in: 3600,
                }),
            ),
        );

        expect(await handleCallback()).toEqual({ returnTo: null });
    });
});
