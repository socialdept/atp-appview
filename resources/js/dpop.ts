import type { DPoPKeyHandle } from './dpop-keys';

/**
 * Stored DPoP nonces keyed by origin.
 *
 * XRPC calls and token endpoint calls use separate nonce maps because
 * the same PDS origin may serve both, but they have independent nonce
 * contexts on the server side.
 */
const xrpcNonces = new Map<string, string>();
const tokenNonces = new Map<string, string>();

/**
 * Base64url encode raw bytes.
 */
function base64url(buffer: ArrayBuffer | Uint8Array): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (const b of bytes) {
        binary += String.fromCharCode(b);
    }
    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

/**
 * Create a DPoP proof JWT for a request.
 *
 * When `accessToken` is provided, the `ath` (access token hash) claim is
 * included in the proof. Omit it for token endpoint requests where no
 * access token is being presented (e.g. refresh_token grant).
 */
export async function createDpopProof(
    handle: DPoPKeyHandle,
    method: string,
    url: string,
    accessToken?: string,
    nonce?: string,
): Promise<string> {
    const header = {
        typ: 'dpop+jwt',
        alg: 'ES256',
        jwk: handle.publicJwk,
    };

    const payload: Record<string, unknown> = {
        jti: crypto.randomUUID(),
        htm: method.toUpperCase(),
        htu: url,
        iat: Math.floor(Date.now() / 1000),
    };

    if (accessToken) {
        const tokenBytes = new TextEncoder().encode(accessToken);
        const hashBuffer = await crypto.subtle.digest('SHA-256', tokenBytes);
        payload.ath = base64url(hashBuffer);
    }

    if (nonce) {
        payload.nonce = nonce;
    }

    const encodedHeader = base64url(
        new TextEncoder().encode(JSON.stringify(header)),
    );
    const encodedPayload = base64url(
        new TextEncoder().encode(JSON.stringify(payload)),
    );

    const signingInput = `${encodedHeader}.${encodedPayload}`;
    const signature = await crypto.subtle.sign(
        { name: 'ECDSA', hash: 'SHA-256' },
        handle.privateKey,
        new TextEncoder().encode(signingInput),
    );

    return `${signingInput}.${base64url(signature)}`;
}

/**
 * Read the DPoP-Nonce header from a response.
 *
 * Returns null when the header is absent or not exposed by CORS
 * (cross-origin responses only expose safe-listed headers by default).
 */
function readNonce(response: Response): string | null {
    return (
        response.headers.get('DPoP-Nonce') ?? response.headers.get('dpop-nonce')
    );
}

/**
 * Execute a fetch with DPoP proof, retrying once on nonce challenges.
 */
async function fetchWithNonceRetry(
    url: string,
    init: RequestInit,
    handle: DPoPKeyHandle,
    nonceMap: Map<string, string>,
    accessToken?: string,
): Promise<Response> {
    const method = init.method ?? 'GET';
    const origin = new URL(url).origin;

    // First attempt, with the stored nonce if there is one.
    const nonce = nonceMap.get(origin);
    const proof = await createDpopProof(
        handle,
        method,
        url,
        accessToken,
        nonce,
    );

    const headers = new Headers(init.headers);
    if (accessToken) {
        headers.set('Authorization', `DPoP ${accessToken}`);
    }
    headers.set('DPoP', proof);

    const response = await fetch(url, { ...init, headers });

    const newNonce = readNonce(response);
    if (newNonce) {
        nonceMap.set(origin, newNonce);
    }

    // Retry once if server requires a (different) nonce
    if (
        (response.status === 400 || response.status === 401) &&
        newNonce &&
        newNonce !== nonce
    ) {
        const body = await response
            .clone()
            .json()
            .catch(() => null);

        if (body?.error === 'use_dpop_nonce') {
            const retryProof = await createDpopProof(
                handle,
                method,
                url,
                accessToken,
                newNonce,
            );

            const retryHeaders = new Headers(init.headers);
            if (accessToken) {
                retryHeaders.set('Authorization', `DPoP ${accessToken}`);
            }
            retryHeaders.set('DPoP', retryProof);

            return fetch(url, { ...init, headers: retryHeaders });
        }
    }

    return response;
}

/**
 * Make a DPoP-authenticated fetch for XRPC calls, handling nonce
 * challenges automatically.
 */
export async function dpopFetch(
    fetchUrl: string,
    init: RequestInit,
    handle: DPoPKeyHandle,
    accessToken: string,
): Promise<Response> {
    return fetchWithNonceRetry(fetchUrl, init, handle, xrpcNonces, accessToken);
}

/**
 * Make a DPoP-authenticated form POST to a token endpoint.
 *
 * Unlike `dpopFetch`, this does NOT set an Authorization header (no access
 * token is presented). Used for grant requests like refresh_token.
 *
 * Uses a separate nonce map from XRPC calls because the PDS token endpoint
 * and XRPC endpoints have independent nonce contexts.
 */
export async function dpopFormPost(
    tokenUrl: string,
    body: Record<string, string>,
    handle: DPoPKeyHandle,
): Promise<Response> {
    return fetchWithNonceRetry(
        tokenUrl,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body),
        },
        handle,
        tokenNonces,
    );
}
