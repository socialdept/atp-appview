import { dpopFetch } from './dpop';
import type { DPoPKeyHandle } from './dpop-keys';
import { appFetch, errorMessage } from './http';
import { config } from './config';

/**
 * How the browser proves to this app who is signed in, so the server can
 * keep an ordinary Laravel session without holding a token: a service auth
 * token from the account's own PDS, signed with its repo key, addressed to
 * this app and bound to one method.
 */
/**
 * Start a server session for an account whose access token is valid now.
 * Takes the token and key directly, because it also runs in the middle of a
 * refresh, under the lock a fresh-session lookup would wait on.
 */
export async function vouchFor(
    session: { did: string; pds: string; accessToken: string },
    key: DPoPKeyHandle,
): Promise<void> {
    const { sessionMethod, audience, routes } = config();

    if (!sessionMethod || !audience) {
        throw new Error(
            'Set atp-appview.auth.frontend.session_method and the service auth audience.',
        );
    }

    const query = new URLSearchParams({
        aud: audience.split('#')[0],
        lxm: sessionMethod,
        exp: String(Math.floor(Date.now() / 1000) + 60),
    });

    const minted = await dpopFetch(
        `${session.pds}/xrpc/com.atproto.server.getServiceAuth?${query}`,
        {},
        key,
        session.accessToken,
    );

    if (!minted.ok) {
        throw new Error('Your PDS would not vouch for you.');
    }

    const { token } = await minted.json();

    const started = await appFetch(routes.startSession.url(), {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
    });

    if (!started.ok) {
        throw new Error(
            await errorMessage(started, 'Could not start your session.'),
        );
    }
}
