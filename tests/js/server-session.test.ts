import { describe, expect, it, vi } from 'vitest';

const pdsUrls: string[] = [];

vi.mock('../../resources/js/dpop', () => ({
    dpopFetch: vi.fn(async (url: string) => {
        pdsUrls.push(url);

        return new Response(JSON.stringify({ token: 'service-token' }));
    }),
}));

vi.mock('../../resources/js/http', () => ({
    appFetch: vi.fn(async () => new Response('{}')),
    errorMessage: async (_response: Response, fallback: string) => fallback,
}));

const { configureAtproto } = await import('../../resources/js/config');
const { vouchFor } = await import('../../resources/js/server-session');

const route = (path: string) => ({ url: () => path });

describe('vouchFor', () => {
    it('asks the PDS for a token addressed to the full service reference', async () => {
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
            audience: 'did:web:app.example#atproto_appview',
            signOut: null,
        });

        await vouchFor(
            { did: 'did:plc:alice', pds: 'https://pds.example', accessToken: 'access' },
            { privateKey: 'k', publicJwk: {} } as never,
        );

        const query = new URL(pdsUrls[0]).searchParams;

        expect(query.get('aud')).toBe('did:web:app.example#atproto_appview');
        expect(query.get('lxm')).toBe('com.example.createSession');
    });
});
