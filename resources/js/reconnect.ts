import { config } from './config';
import { getDpopKeyHandle } from './dpop-keys';
import { appFetch } from './http';
import {
    account,
    activeDid,
    currentSession,
    expireSession,
    forgetSession,
    onSessionChange,
    revokeSession,
    SessionExpiredError,
    setActive,
    startServerSession,
} from './session';
import type { AtpSession } from './session';

/**
 * Keeping the browser's atproto session and the server's Laravel session in
 * step, and signing an account back in without losing what the user was
 * doing.
 *
 * The browser session is the source of truth. The server session can be
 * rebuilt from it at any time with a service auth token, so the server
 * never logs anyone out when tokens die: it keeps them browsing, and the
 * page offers to reconnect.
 */
export class ReconnectCanceledError extends Error {
    constructor() {
        super('You need to sign in again to do that.');
        this.name = 'ReconnectCanceledError';
    }
}

export interface ServerUser {
    did: string | null;
    handle: string | null;
}

interface Waiter {
    resolve: () => void;
    reject: (error: Error) => void;
}

export interface ReconnectState {
    // The server's user, whose browser session needs signing back in.
    needed: { did: string; handle: string | null } | null;
    // An action is waiting on that sign-in, so ask now rather than offer.
    prompting: boolean;
}

let state: ReconnectState = { needed: null, prompting: false };
const waiters: Waiter[] = [];
const stateListeners = new Set<(state: ReconnectState) => void>();

/**
 * Whether an account needs signing back in, for the page to show a banner
 * or a dialog. Each change replaces the object, so it suits any framework's
 * reactivity: in Vue, keep it in a shallowRef updated by onReconnectChange.
 */
export function reconnectState(): ReconnectState {
    return state;
}

export function onReconnectChange(
    listener: (state: ReconnectState) => void,
): () => void {
    stateListeners.add(listener);

    return () => stateListeners.delete(listener);
}

function update(patch: Partial<ReconnectState>): void {
    state = { ...state, ...patch };
    stateListeners.forEach((listener) => listener(state));
}

let serverUser: ServerUser | null = null;
const rebuilding = new Set<string>();

/**
 * Compare the two sessions and fix what can be fixed without the user.
 * Runs on every page load and navigation, and when another tab changes the
 * browser session.
 */
export async function reconcile(
    user: ServerUser | null,
): Promise<'reload' | null> {
    serverUser = user;

    const browser = await usable(currentSession());

    if (browser && browser.did !== user?.did) {
        // The browser can act as an account the server does not know is
        // signed in: its cookie expired, or it moved to another account.
        // Follow the browser and vouch for it again.
        return (await rebuild(browser)) ? 'reload' : null;
    }

    if (user?.did && !browser) {
        const stored = account(user.did);

        if (
            stored?.tokens &&
            activeDid() !== user.did &&
            (await usable(stored as AtpSession))
        ) {
            setActive(user.did);
            clearNeeded();

            return null;
        }

        update({
            needed: {
                did: user.did,
                handle: stored?.handle ?? user.handle,
            },
        });

        return null;
    }

    clearNeeded();

    return null;
}

/**
 * The session, if this browser can still act with it. Tokens without their
 * key are worthless: site data cleared in part, or the key store wiped.
 */
async function usable(session: AtpSession | null): Promise<AtpSession | null> {
    if (!session) {
        return null;
    }

    if (await getDpopKeyHandle(session.did).catch(() => null)) {
        return session;
    }

    await expireSession(session.did, 'key-missing');

    return null;
}

// An action waiting on a sign-in keeps its account named until it settles.
function clearNeeded(): void {
    if (!state.prompting && state.needed !== null) {
        update({ needed: null });
    }
}

async function rebuild(session: AtpSession): Promise<boolean> {
    // One attempt per account until something changes, so a server that
    // refuses cannot make every navigation try again.
    if (rebuilding.has(session.did)) {
        return false;
    }

    rebuilding.add(session.did);

    try {
        await startServerSession(session.did);

        return true;
    } catch {
        // A dead session has already moved itself to expired. Anything else
        // leaves the server as it is until the next change.
        return false;
    }
}

/**
 * Run something that needs the account's session. If the session turns out
 * to be dead, the user is asked to sign back in and it runs once more, with
 * the page and everything on it untouched. Rejects with
 * ReconnectCanceledError when they decline.
 *
 * Only a SessionExpiredError triggers the retry, and that error is only
 * thrown before a request is sent or after the PDS refused its token, so
 * the first attempt never took effect.
 */
export async function withSession<T>(action: () => Promise<T>): Promise<T> {
    try {
        return await action();
    } catch (e) {
        if (!(e instanceof SessionExpiredError)) {
            throw e;
        }

        await requestReconnect(e.did);

        return action();
    }
}

function requestReconnect(did: string): Promise<void> {
    const stored = account(did);
    update({
        needed: {
            did,
            handle:
                stored?.handle ??
                (serverUser?.did === did ? serverUser.handle : null),
        },
        prompting: true,
    });

    return new Promise((resolve, reject) => {
        waiters.push({ resolve, reject });
    });
}

/**
 * The account signed back in: let waiting actions run again.
 */
export function reconnected(): void {
    const waiting = waiters.splice(0);
    update({ needed: null, prompting: false });
    rebuilding.clear();
    waiting.forEach((waiter) => waiter.resolve());
}

/**
 * The user declined to sign back in: the waiting actions give up, and the
 * banner stays.
 */
export function dismissReconnect(): void {
    const waiting = waiters.splice(0);
    update({ prompting: false });
    waiting.forEach((waiter) => waiter.reject(new ReconnectCanceledError()));
}

/**
 * Keep the two sessions in step for the life of the page. `reload` asks the
 * page for fresh server props after the server session changed. While
 * `paused` holds, as on the sign-in pages that set both sessions up
 * themselves, changes are left alone.
 */
export function syncSessions(options: {
    reload: () => void;
    paused: () => boolean;
}): () => void {
    return onSessionChange(async (event) => {
        if (event.type === 'updated') {
            rebuilding.delete(event.did);
        }

        if (!options.paused() && (await reconcile(serverUser)) === 'reload') {
            options.reload();
        }
    });
}

/**
 * Sign out everywhere: revoke the tokens at the authorization server, forget
 * them and the key here, and end the server session.
 */
export async function signOut(): Promise<void> {
    // Nobody is signed in at the server from here on, so forgetting the
    // browser session must not read as a session that needs reconnecting.
    serverUser = null;
    update({ needed: null });

    const session = currentSession();
    const did = activeDid();

    if (session) {
        await revokeSession(session);
    }

    if (did) {
        await forgetSession(did);
    }

    await appFetch(config().routes.endSession.url(), { method: 'DELETE' });
}
