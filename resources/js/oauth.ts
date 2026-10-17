import { dpopFormPost } from './dpop';
import { config, storageName } from './config';
import {
    deleteKeyPair,
    generateKeyPair,
    getDpopKeyHandle,
    reKeyPair,
    storeKeyPair,
} from './dpop-keys';
import { appFetch, clientAssertion, errorMessage } from './http';
import { vouchFor } from './server-session';
import { saveSession } from './session';
import type { AtpSession } from './session';

/**
 * Frontend-mode sign-in.
 *
 * The browser pushes the authorization request and exchanges the code with
 * its own non-extractable DPoP key. Then it checks that the account the
 * server returned belongs to that server, and hands the server a service
 * auth token from its own PDS so a Laravel session can start without the
 * server ever holding a token.
 *
 * A sign-in either leaves the page for the authorization server, or runs in
 * a popup so the page and whatever the user was doing on it stay put.
 */
export type SignIn =
    | { provider: string; create?: boolean }
    | { handle: string }
    | { did: string };

export interface CallbackFlow {
    code: string;
    state: string;
    codeVerifier: string;
    issuer: string;
    tokenEndpoint: string;
    redirectUri: string | null;
    clientId: string | null;
    handle: string | null;
    did: string | null;
}

export type PopupResult =
    | { state: string; status: 'signed-in'; did: string }
    | { state: string; status: 'failed'; message: string };

/**
 * The user closed the popup, or never finished in it.
 */
export class SignInCanceledError extends Error {
    constructor() {
        super('Sign-in was canceled.');
        this.name = 'SignInCanceledError';
    }
}

/**
 * The browser blocked the popup.
 */
export class PopupBlockedError extends Error {
    constructor() {
        super('Your browser blocked the sign-in window.');
        this.name = 'PopupBlockedError';
    }
}

const stateKey = (state: string) => `oauth_state:${state}`;
const popupKey = (state: string) => storageName(`oauth_popup:${state}`);
const returnKey = (state: string) => storageName(`oauth_return:${state}`);
const POPUP_CHANNEL = () => storageName('oauth_popup');
const POPUP_TIMEOUT_MS = 5 * 60_000;

/**
 * Prepare a sign-in and push its authorization request. Answers the URL of
 * the authorization server's page and the flow's state.
 */
async function pushAuthorization(
    input: SignIn,
): Promise<{ url: string; state: string }> {
    const prepared = await appFetch(config().routes.prepare.url(), {
        method: 'POST',
        body: JSON.stringify(input),
    });

    if (!prepared.ok) {
        throw new Error(
            await errorMessage(prepared, 'Could not start signing in.'),
        );
    }

    const flow = await prepared.json();

    // The same key signs the pushed request and the token exchange, so the
    // tokens end up bound to it.
    const keys = await generateKeyPair();
    await storeKeyPair(stateKey(flow.state), keys);

    const pushed = await dpopFormPost(
        flow.parEndpoint,
        {
            ...(await clientAssertion(flow.issuer)),
            redirect_uri: flow.redirectUri ?? '',
            response_type: 'code',
            scope: flow.scope,
            code_challenge: flow.codeChallenge,
            code_challenge_method: flow.codeChallengeMethod,
            state: flow.state,
            ...(flow.loginHint ? { login_hint: flow.loginHint } : {}),
            ...(flow.prompt ? { prompt: flow.prompt } : {}),
        },
        { privateKey: keys.privateKey, publicJwk: keys.publicJwk },
    );

    if (!pushed.ok) {
        throw new Error('The server refused the sign-in request.');
    }

    const { request_uri: requestUri } = await pushed.json();
    const authorize = new URL(flow.authorizationEndpoint);
    authorize.searchParams.set('request_uri', requestUri);
    authorize.searchParams.set('client_id', flow.clientId);

    return { url: authorize.toString(), state: flow.state };
}

/**
 * Start a sign-in and leave for the authorization server. With a return
 * URL, the callback comes back to it instead of the default page.
 */
export async function signIn(input: SignIn, returnTo?: string): Promise<void> {
    const { url, state } = await pushAuthorization(input);

    if (returnTo) {
        localStorage.setItem(returnKey(state), returnTo);
    }

    window.location.href = url;
}

/**
 * Sign an account back in through a popup, leaving this page as it is.
 * Must be called straight from a click: the popup opens before anything is
 * awaited, or the browser blocks it.
 */
export async function signInPopup(did: string): Promise<void> {
    const popup = window.open(
        'about:blank',
        storageName('sign_in'),
        'popup,width=600,height=720',
    );

    if (!popup) {
        throw new PopupBlockedError();
    }

    let state: string;

    try {
        const pushed = await pushAuthorization({ did });
        state = pushed.state;
        localStorage.setItem(popupKey(state), '1');
        popup.location.href = pushed.url;
    } catch (e) {
        popup.close();
        throw e;
    }

    try {
        await awaitPopup(state, popup);
    } finally {
        localStorage.removeItem(popupKey(state));
    }
}

function awaitPopup(state: string, popup: Window): Promise<void> {
    return new Promise((resolve, reject) => {
        const channel = new BroadcastChannel(POPUP_CHANNEL());

        const done = (settle: () => void) => {
            clearInterval(closed);
            clearTimeout(timeout);
            channel.close();
            settle();
        };

        channel.onmessage = ({ data }: MessageEvent<PopupResult>) => {
            if (data?.state !== state || !('status' in data)) {
                return;
            }

            channel.postMessage({ state, ack: true });

            done(() =>
                data.status === 'signed-in'
                    ? resolve()
                    : reject(new Error(data.message)),
            );
        };

        const closed = setInterval(() => {
            if (popup.closed) {
                done(() => reject(new SignInCanceledError()));
            }
        }, 500);

        const timeout = setTimeout(() => {
            popup.close();
            done(() => reject(new SignInCanceledError()));
        }, POPUP_TIMEOUT_MS);
    });
}

/**
 * Whether this callback belongs to a sign-in running in a popup.
 */
export function isPopupFlow(state: string): boolean {
    return localStorage.getItem(popupKey(state)) !== null;
}

/**
 * Where a redirect sign-in asked to come back to, if anywhere on this site.
 */
export function takeReturnUrl(state: string): string | null {
    const url = localStorage.getItem(returnKey(state));
    localStorage.removeItem(returnKey(state));

    return url &&
        new URL(url, window.location.origin).origin === window.location.origin
        ? url
        : null;
}

/**
 * Report a popup sign-in's outcome to the page that opened it. True once
 * that page has heard it.
 */
export function reportToOpener(result: PopupResult): Promise<boolean> {
    return new Promise((resolve) => {
        const channel = new BroadcastChannel(POPUP_CHANNEL());
        const timeout = setTimeout(() => {
            channel.close();
            resolve(false);
        }, 1000);

        channel.onmessage = ({ data }) => {
            if (data?.state === result.state && data.ack) {
                clearTimeout(timeout);
                channel.close();
                resolve(true);
            }
        };

        channel.postMessage(result);
    });
}

/**
 * Finish a sign-in on the callback page: exchange the code, check the
 * account, store the session and start one on the server.
 */
export async function completeSignIn(flow: CallbackFlow): Promise<AtpSession> {
    const key = await getDpopKeyHandle(stateKey(flow.state));

    if (!key) {
        throw new Error('This sign-in started in another browser. Try again.');
    }

    const exchanged = await dpopFormPost(
        flow.tokenEndpoint,
        {
            ...(await clientAssertion(flow.issuer)),
            grant_type: 'authorization_code',
            code: flow.code,
            redirect_uri: flow.redirectUri ?? '',
            code_verifier: flow.codeVerifier,
        },
        key,
    );

    if (!exchanged.ok) {
        throw new Error('The server would not complete the sign-in.');
    }

    const tokens = await exchanged.json();
    const did: string = tokens.sub ?? '';

    if (!did.startsWith('did:')) {
        throw new Error('The sign-in named no account.');
    }

    // Signing a known account back in must land on that account, not on
    // whichever one happens to be signed in at the server.
    if (flow.did && did !== flow.did) {
        await deleteKeyPair(stateKey(flow.state)).catch(() => undefined);
        throw new Error('You signed in with a different account. Try again.');
    }

    // Any authorization server could name any account: the account's own
    // PDS must use the server that signed it in, and a sign-in that started
    // from a handle must land on that handle.
    const resolved = await appFetch(
        config().routes.account.url({ query: { did } }),
    );

    if (!resolved.ok) {
        throw new Error(
            await errorMessage(resolved, 'Could not look up the account.'),
        );
    }

    const account = await resolved.json();

    if (account.issuer !== flow.issuer.replace(/\/+$/, '')) {
        throw new Error('That server may not sign in this account.');
    }

    if (flow.handle && account.handle !== flow.handle) {
        throw new Error(
            `Signed in as ${account.handle ?? did}, not ${flow.handle}.`,
        );
    }

    await reKeyPair(stateKey(flow.state), did);

    const session: AtpSession = {
        did,
        handle: account.handle,
        pds: account.pds,
        issuer: account.issuer,
        tokenEndpoint: flow.tokenEndpoint,
        revocationEndpoint: account.revocationEndpoint ?? null,
        tokens: {
            accessToken: tokens.access_token,
            refreshToken: tokens.refresh_token,
            expiresAt: new Date(
                Date.now() + (tokens.expires_in ?? 300) * 1000,
            ).toISOString(),
            scope: tokens.scope ? tokens.scope.split(' ') : [],
        },
    };

    saveSession(session);

    const signingKey = await getDpopKeyHandle(did);

    if (!signingKey) {
        throw new Error('Could not keep your sign-in key in this browser.');
    }

    await vouchFor(
        { did, pds: session.pds, accessToken: session.tokens.accessToken },
        signingKey,
    );

    return session;
}

/**
 * The flow the callback held for this browser, collected once.
 */
async function collectFlow(): Promise<CallbackFlow> {
    const response = await appFetch(config().routes.flow.url(), {
        method: 'POST',
    });

    if (!response.ok) {
        throw new Error(
            await errorMessage(
                response,
                'That sign-in has expired. Try again.',
            ),
        );
    }

    return response.json();
}

/**
 * Run on the page the callback redirects to. Finishes the sign-in, then
 * either hands back to the page that opened the popup and closes it, or
 * answers where to go next: the URL the sign-in asked to return to, or null
 * for the app's default. Throws with a message fit to show when the sign-in
 * fails, after telling the opener.
 */
export async function handleCallback(): Promise<{
    returnTo: string | null;
} | null> {
    const flow = await collectFlow();
    const popup = isPopupFlow(flow.state);
    const returnTo = takeReturnUrl(flow.state);

    try {
        const session = await completeSignIn(flow);

        // A popup hands back to the page that opened it and closes. If that
        // page is gone, the sign-in still counts, so carry on here.
        if (
            popup &&
            (await reportToOpener({
                state: flow.state,
                status: 'signed-in',
                did: session.did,
            }))
        ) {
            window.close();

            return null;
        }

        return { returnTo };
    } catch (e) {
        const message = e instanceof Error ? e.message : 'Sign-in failed.';

        if (popup) {
            await reportToOpener({
                state: flow.state,
                status: 'failed',
                message,
            });
        }

        throw new Error(message);
    }
}
