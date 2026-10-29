import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const keys = new Map<string, object>();
const refreshResponses: Array<() => Response | Promise<Response>> = [];
const pdsResponses: Array<(token: string) => Response> = [];
const assertion = vi.fn(async (_issuer: string) => ({
    client_assertion: 'signed',
}));
const vouch = vi.fn(async (..._args: unknown[]) => undefined);

vi.mock('../../resources/js/dpop-keys', () => ({
    getDpopKeyHandle: async (did: string) => keys.get(did) ?? null,
    deleteKeyPair: async (did: string) => void keys.delete(did),
}));

vi.mock('../../resources/js/dpop', () => ({
    dpopFormPost: vi.fn(async () => refreshResponses.shift()!()),
    dpopFetch: vi.fn(
        async (_url: string, _init: RequestInit, _key: object, token: string) =>
            pdsResponses.shift()!(token),
    ),
}));

vi.mock('../../resources/js/http', async () => {
    class AssertionRefusedError extends Error {}

    return {
        AssertionRefusedError,
        clientAssertion: (issuer: string) => assertion(issuer),
        appFetch: vi.fn(async () => new Response('{}')),
    };
});

vi.mock('../../resources/js/server-session', () => ({
    vouchFor: (...args: unknown[]) => vouch(...args),
}));

const { configureAtproto } = await import('../../resources/js/config');
const session = await import('../../resources/js/session');
const http = await import('../../resources/js/http');
const dpop = await import('../../resources/js/dpop');
const {
    withSession,
    reconnectState,
    reconnected,
    dismissReconnect,
    reconcile,
} = await import('../../resources/js/reconnect');

const route = (path: string) => ({ url: () => path });

// As each page configures it, with what FrontendAuth::browserConfig() sends.
const configure = (signOut: string | null = null) =>
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
        signOut,
    });

configure();

const DID = 'did:plc:alice';

function store(
    overrides: Partial<{
        accessToken: string;
        refreshToken: string;
        expiresIn: number;
        revocationEndpoint: string | null;
    }> = {},
) {
    keys.set(DID, { privateKey: 'k', publicJwk: {} });
    session.saveSession({
        did: DID,
        handle: 'alice.test',
        pds: 'https://pds.example',
        issuer: 'https://auth.example',
        tokenEndpoint: 'https://auth.example/oauth/token',
        revocationEndpoint: overrides.revocationEndpoint ?? null,
        tokens: {
            accessToken: overrides.accessToken ?? 'access-1',
            refreshToken: overrides.refreshToken ?? 'refresh-1',
            expiresAt: new Date(
                Date.now() + (overrides.expiresIn ?? 3600) * 1000,
            ).toISOString(),
            scope: ['atproto'],
        },
    });
}

const json = (
    status: number,
    body: unknown,
    headers: Record<string, string> = {},
) => new Response(JSON.stringify(body), { status, headers });

const refreshed = (n: number) => () =>
    json(200, {
        access_token: `access-${n}`,
        refresh_token: `refresh-${n}`,
        expires_in: 3600,
    });

beforeEach(async () => {
    const data = new Map<string, string>();
    vi.stubGlobal('localStorage', {
        getItem: (k: string) => data.get(k) ?? null,
        setItem: (k: string, v: string) => void data.set(k, String(v)),
        removeItem: (k: string) => void data.delete(k),
    });
    keys.clear();
    refreshResponses.length = 0;
    pdsResponses.length = 0;
    assertion.mockClear();
    vouch.mockClear();
    dismissReconnect();
    await reconcile(null);
});

describe('refresh', () => {
    it('leaves a fresh token alone', async () => {
        store();

        expect((await session.freshSession(DID)).tokens.accessToken).toBe(
            'access-1',
        );
        expect(refreshResponses).toHaveLength(0);
    });

    it('spends a refresh token once when several requests need it', async () => {
        store({ expiresIn: 30 });
        refreshResponses.push(refreshed(2));

        const results = await Promise.all([
            session.freshSession(DID),
            session.freshSession(DID),
            session.freshSession(DID),
        ]);

        expect(results.map((s) => s.tokens.accessToken)).toEqual([
            'access-2',
            'access-2',
            'access-2',
        ]);
        expect(session.account(DID)?.tokens?.refreshToken).toBe('refresh-2');
    });

    it('keeps the session through network failures and server errors', async () => {
        store({ expiresIn: 30 });
        refreshResponses.push(() =>
            Promise.reject(new TypeError('Failed to fetch')),
        );
        refreshResponses.push(() => json(503, {}));
        refreshResponses.push(() => json(400, { error: 'something_new' }));

        for (let i = 0; i < 3; i++) {
            await expect(session.freshSession(DID)).rejects.toBeInstanceOf(
                session.TransientSessionError,
            );
        }

        expect(session.account(DID)?.tokens?.refreshToken).toBe('refresh-1');
        expect(keys.has(DID)).toBe(true);
    });

    it('expires the session on a dead grant but keeps the account', async () => {
        store({ expiresIn: 30 });
        refreshResponses.push(() => json(400, { error: 'invalid_grant' }));
        const events: string[] = [];
        session.onSessionChange((e) => events.push(e.type));

        await expect(session.freshSession(DID)).rejects.toBeInstanceOf(
            session.SessionExpiredError,
        );

        expect(session.account(DID)).toMatchObject({
            handle: 'alice.test',
            tokens: null,
        });
        expect(keys.has(DID)).toBe(false);
        expect(events).toContain('expired');
    });

    it('adopts tokens another tab refreshed instead of expiring', async () => {
        store({ expiresIn: 30 });
        refreshResponses.push(() => {
            // Another tab spent the token first and stored its result.
            const current = session.account(DID)!;
            session.saveSession({
                ...current,
                tokens: {
                    ...current.tokens!,
                    accessToken: 'access-9',
                    refreshToken: 'refresh-9',
                },
            } as never);

            return json(400, { error: 'invalid_grant' });
        });

        expect((await session.freshSession(DID)).tokens.accessToken).toBe(
            'access-9',
        );
    });

    it('vouches for the account again when the server forgot who is signed in', async () => {
        store({ expiresIn: 30 });
        assertion.mockRejectedValueOnce(new http.AssertionRefusedError('no'));
        refreshResponses.push(refreshed(2));

        expect((await session.freshSession(DID)).tokens.accessToken).toBe(
            'access-2',
        );
        expect(vouch).toHaveBeenCalledOnce();
        expect(assertion).toHaveBeenCalledTimes(2);
    });

    it('expires when the server forgot and the access token is spent', async () => {
        store({ expiresIn: 2 });
        assertion.mockRejectedValueOnce(new http.AssertionRefusedError('no'));

        await expect(session.freshSession(DID)).rejects.toMatchObject({
            reason: 'server-session',
        });
        expect(vouch).not.toHaveBeenCalled();
    });
});

describe('pdsFetch', () => {
    const invalidToken = () =>
        json(
            401,
            { error: 'invalid_token' },
            { 'WWW-Authenticate': 'DPoP error="invalid_token"' },
        );

    it('refreshes and retries once when the PDS rejects the token', async () => {
        store();
        pdsResponses.push(invalidToken, (token) => json(200, { token }));
        refreshResponses.push(refreshed(2));

        const response = await session.pdsFetch(DID, '/xrpc/x', {
            method: 'POST',
            body: '{}',
        });

        expect(await response.json()).toEqual({ token: 'access-2' });
    });

    it('does not refresh for an ordinary 401', async () => {
        store();
        pdsResponses.push(() => json(401, { error: 'AuthRequired' }));

        expect((await session.pdsFetch(DID, '/xrpc/x')).status).toBe(401);
        expect(refreshResponses).toHaveLength(0);
    });

    it('expires the session when the fresh token is rejected too', async () => {
        store();
        pdsResponses.push(invalidToken, invalidToken);
        refreshResponses.push(refreshed(2));

        await expect(session.pdsFetch(DID, '/xrpc/x')).rejects.toMatchObject({
            reason: 'token-rejected',
        });
        expect(session.account(DID)?.tokens).toBeNull();
    });
});

describe('withSession', () => {
    it('waits for the account to sign back in, then runs the action again', async () => {
        store({ expiresIn: 30 });
        refreshResponses.push(() => json(400, { error: 'invalid_grant' }));
        pdsResponses.push(() => json(200, { ok: true }));
        let attempts = 0;

        const pending = withSession(async () => {
            attempts++;

            return (
                await session.pdsFetch(DID, '/xrpc/x', {
                    method: 'POST',
                    body: '{}',
                })
            ).json();
        });

        await vi.waitFor(() => expect(reconnectState().prompting).toBe(true));
        expect(reconnectState().needed).toEqual({
            did: DID,
            handle: 'alice.test',
        });

        store();
        reconnected();

        expect(await pending).toEqual({ ok: true });
        expect(attempts).toBe(2);
    });

    it('gives up when the user declines', async () => {
        store({ expiresIn: 30 });
        refreshResponses.push(() => json(400, { error: 'invalid_grant' }));

        const pending = withSession(() => session.pdsFetch(DID, '/xrpc/x'));
        await vi.waitFor(() => expect(reconnectState().prompting).toBe(true));
        dismissReconnect();

        await expect(pending).rejects.toThrow(
            'You need to sign in again to do that.',
        );
    });
});

describe('reconcile', () => {
    it('rebuilds the server session from the browser one', async () => {
        store();

        expect(await reconcile(null)).toBe('reload');
        expect(vouch).toHaveBeenCalledOnce();
    });

    it('asks to reconnect when the server is signed in but the browser is not', async () => {
        expect(await reconcile({ did: DID, handle: 'alice.test' })).toBeNull();
        expect(reconnectState().needed).toEqual({
            did: DID,
            handle: 'alice.test',
        });
    });

    it('asks to reconnect when the tokens are here but their key is gone', async () => {
        store();
        keys.delete(DID);

        expect(await reconcile({ did: DID, handle: 'alice.test' })).toBeNull();
        expect(reconnectState().needed).toEqual({
            did: DID,
            handle: 'alice.test',
        });
        expect(session.account(DID)?.tokens).toBeNull();
    });

    it('does nothing when both agree', async () => {
        store();

        expect(await reconcile({ did: DID, handle: 'alice.test' })).toBeNull();
        expect(reconnectState().needed).toBeNull();
        expect(vouch).not.toHaveBeenCalled();
    });
    describe('after a server sign-out', () => {
        afterEach(() => configure());

        it('signs the browser out instead of rebuilding the server session', async () => {
            store({ revocationEndpoint: 'https://auth.example/oauth/revoke' });
            refreshResponses.push(() => new Response('', { status: 200 }));
            configure('sign-out-1');

            expect(await reconcile(null)).toBeNull();
            expect(vouch).not.toHaveBeenCalled();
            expect(vi.mocked(dpop.dpopFormPost)).toHaveBeenLastCalledWith(
                'https://auth.example/oauth/revoke',
                expect.objectContaining({
                    token: 'refresh-1',
                    token_type_hint: 'refresh_token',
                }),
                expect.anything(),
            );
            expect(session.account(DID)).toBeNull();
            expect(keys.has(DID)).toBe(false);
            expect(reconnectState().needed).toBeNull();
            expect(http.appFetch).not.toHaveBeenCalledWith('/session', {
                method: 'DELETE',
            });
        });

        it('follows each sign-out once', async () => {
            configure('sign-out-2');
            await reconcile(null);

            // Signed back in on the same page, whose config still says so.
            store();
            reconnected();

            expect(await reconcile(null)).toBe('reload');
            expect(vouch).toHaveBeenCalledOnce();
        });

        it('leaves a session the server already has alone', async () => {
            store();
            configure('sign-out-3');

            expect(
                await reconcile({ did: DID, handle: 'alice.test' }),
            ).toBeNull();
            expect(session.account(DID)?.tokens?.refreshToken).toBe('refresh-1');
        });
    });
});
