import { dpopFetch, dpopFormPost } from './dpop';
import { deleteKeyPair, getDpopKeyHandle } from './dpop-keys';
import type { DPoPKeyHandle } from './dpop-keys';
import { AssertionRefusedError, clientAssertion } from './http';
import { storageName } from './config';
import { vouchFor } from './server-session';

/**
 * Frontend-mode sessions: the OAuth tokens live in the browser, per DID.
 *
 * The tokens are in localStorage, but they are DPoP-bound: every use needs a
 * proof signed with the account's non-extractable key in IndexedDB, so the
 * tokens alone are worth nothing to whoever reads them.
 *
 * An account whose tokens die stays in the store without them, so its handle
 * is at hand to sign it back in. Every tab shares the store: refreshes run
 * under a per-account lock, and changes are announced to the other tabs.
 */
export interface AtpTokens {
    accessToken: string;
    refreshToken: string;
    expiresAt: string;
    scope: string[];
}

export interface AtpAccount {
    did: string;
    handle: string | null;
    pds: string;
    issuer: string;
    tokenEndpoint: string;
    revocationEndpoint: string | null;
    tokens: AtpTokens | null;
}

export type AtpSession = AtpAccount & { tokens: AtpTokens };

export type SessionEvent =
    | { type: 'updated'; did: string }
    | { type: 'expired'; did: string; reason: string }
    | { type: 'removed'; did: string };

/**
 * The account's tokens are dead and only signing in again brings it back.
 * Thrown before a request is sent, or after the PDS refused it, so whatever
 * was being attempted did not happen and can be retried after signing in.
 */
export class SessionExpiredError extends Error {
    constructor(
        public readonly did: string,
        public readonly reason: string,
    ) {
        super('Your session has ended. Sign in again to continue.');
        this.name = 'SessionExpiredError';
    }
}

/**
 * The session could not be refreshed right now, but it is not dead: the
 * network, a server error, or an answer nobody has classified yet.
 */
export class TransientSessionError extends Error {
    constructor(message = 'Could not reach your server. Try again.') {
        super(message);
        this.name = 'TransientSessionError';
    }
}

const ACCOUNTS_KEY = () => storageName('accounts');
const ACTIVE_KEY = () => storageName('active');
const CHANNEL = () => storageName('session');

// Refresh this long before the access token expires.
const REFRESH_MARGIN_MS = 60_000;

// Refresh answers that mean the grant is gone for good. Everything else,
// including errors nobody has seen yet, leaves the session alone.
const DEAD_GRANT_ERRORS = [
    'invalid_grant',
    'invalid_client',
    'unauthorized_client',
];

function readAccounts(): Record<string, AtpAccount> {
    try {
        return JSON.parse(localStorage.getItem(ACCOUNTS_KEY()) ?? '{}');
    } catch {
        return {};
    }
}

function writeAccount(did: string, account: AtpAccount | null): void {
    // Re-read first: another tab may have written since this one last looked.
    const { [did]: _previous, ...rest } = readAccounts();
    localStorage.setItem(
        ACCOUNTS_KEY(),
        JSON.stringify(account ? { ...rest, [did]: account } : rest),
    );
}

export function account(did: string): AtpAccount | null {
    return readAccounts()[did] ?? null;
}

export function activeDid(): string | null {
    return localStorage.getItem(ACTIVE_KEY());
}

export function currentAccount(): AtpAccount | null {
    const did = activeDid();

    return did ? account(did) : null;
}

export function currentSession(): AtpSession | null {
    const current = currentAccount();

    return current?.tokens ? (current as AtpSession) : null;
}

export function setActive(did: string): void {
    localStorage.setItem(ACTIVE_KEY(), did);
    announce({ type: 'updated', did });
}

export function saveSession(session: AtpSession): void {
    writeAccount(session.did, session);
    localStorage.setItem(ACTIVE_KEY(), session.did);
    announce({ type: 'updated', did: session.did });
}

/**
 * Drop an account's tokens and key but keep the account, so it can sign
 * back in with its handle prefilled.
 */
export async function expireSession(
    did: string,
    reason: string,
): Promise<void> {
    const existing = account(did);

    if (existing) {
        writeAccount(did, { ...existing, tokens: null });
    }

    await deleteKey(did);
    announce({ type: 'expired', did, reason });
}

/**
 * Forget an account entirely, as on signing out.
 */
export async function forgetSession(did: string): Promise<void> {
    writeAccount(did, null);

    if (activeDid() === did) {
        localStorage.removeItem(ACTIVE_KEY());
    }

    await deleteKey(did);
    announce({ type: 'removed', did });
}

async function deleteKey(did: string): Promise<void> {
    try {
        await deleteKeyPair(did);
    } catch {
        // IndexedDB can be unavailable, in private windows for one.
    }
}

const listeners = new Set<(event: SessionEvent) => void>();
let channel: BroadcastChannel | null = null;

function openChannel(): BroadcastChannel | null {
    if (!channel && typeof BroadcastChannel !== 'undefined') {
        channel = new BroadcastChannel(CHANNEL());
        channel.onmessage = (message: MessageEvent<SessionEvent>) =>
            emit(message.data);
    }

    return channel;
}

function emit(event: SessionEvent): void {
    for (const listener of listeners) {
        try {
            listener(event);
        } catch {
            // One broken listener must not stop the others.
        }
    }
}

// Tell this tab and every other one. A tab never re-announces what it
// hears, so tabs cannot echo each other.
function announce(event: SessionEvent): void {
    openChannel()?.postMessage(event);
    emit(event);
}

/**
 * Hear about session changes in this tab and the others.
 */
export function onSessionChange(
    listener: (event: SessionEvent) => void,
): () => void {
    openChannel();
    listeners.add(listener);

    return () => listeners.delete(listener);
}

const fallbackLocks = new Map<string, Promise<unknown>>();

/**
 * Run one thing at a time per name, across tabs where the browser has the
 * Web Locks API and within this tab otherwise.
 */
function withLock<T>(name: string, task: () => Promise<T>): Promise<T> {
    if (typeof navigator !== 'undefined' && navigator.locks) {
        return navigator.locks.request(name, task) as Promise<T>;
    }

    const previous = fallbackLocks.get(name) ?? Promise.resolve();
    const next = previous.catch(() => undefined).then(task);
    fallbackLocks.set(name, next);

    return next;
}

function isStale(tokens: AtpTokens): boolean {
    return (
        new Date(tokens.expiresAt).getTime() - Date.now() <= REFRESH_MARGIN_MS
    );
}

function sessionFor(did: string): AtpSession {
    const current = account(did);

    if (!current?.tokens) {
        throw new SessionExpiredError(did, 'signed-out');
    }

    return current as AtpSession;
}

/**
 * The account's session with an access token good for at least another
 * minute, refreshing it first if not.
 */
export async function freshSession(did: string): Promise<AtpSession> {
    const session = sessionFor(did);

    if (!isStale(session.tokens)) {
        return session;
    }

    return refreshUnless(did, (latest) => !isStale(latest.tokens));
}

/**
 * Refresh after the PDS rejected an access token, unless another tab or
 * request has already replaced that token.
 */
async function refreshAfterRejection(
    did: string,
    rejected: string,
): Promise<AtpSession> {
    return refreshUnless(
        did,
        (latest) => latest.tokens.accessToken !== rejected,
    );
}

function refreshUnless(
    did: string,
    settled: (latest: AtpSession) => boolean,
): Promise<AtpSession> {
    return withLock(storageName(`refresh:${did}`), async () => {
        // Refresh tokens are single use. Whoever held the lock before may
        // have refreshed already, here or in another tab, so read again.
        const latest = sessionFor(did);

        return settled(latest) ? latest : refresh(latest);
    });
}

async function refresh(session: AtpSession): Promise<AtpSession> {
    const key = await getDpopKeyHandle(session.did).catch(() => null);

    if (!key) {
        await expireSession(session.did, 'key-missing');
        throw new SessionExpiredError(session.did, 'key-missing');
    }

    let response: Response;

    try {
        response = await dpopFormPost(
            session.tokenEndpoint,
            {
                ...(await assertionFor(session, key)),
                grant_type: 'refresh_token',
                refresh_token: session.tokens.refreshToken,
            },
            key,
        );
    } catch (e) {
        throw e instanceof SessionExpiredError
            ? e
            : new TransientSessionError();
    }

    if (response.ok) {
        const data = await response.json();
        const refreshed: AtpSession = {
            ...session,
            tokens: {
                accessToken: data.access_token,
                refreshToken: data.refresh_token,
                expiresAt: new Date(
                    Date.now() + (data.expires_in ?? 300) * 1000,
                ).toISOString(),
                scope: data.scope
                    ? data.scope.split(' ')
                    : session.tokens.scope,
            },
        };

        writeAccount(refreshed.did, refreshed);
        announce({ type: 'updated', did: refreshed.did });

        return refreshed;
    }

    const body = await response.json().catch(() => null);

    if (!DEAD_GRANT_ERRORS.includes(body?.error)) {
        throw new TransientSessionError();
    }

    // A browser without Web Locks cannot stop another tab from spending the
    // same refresh token first. If the stored token moved on, that is what
    // happened, and the session is fine.
    const latest = account(session.did);

    if (
        latest?.tokens &&
        latest.tokens.refreshToken !== session.tokens.refreshToken
    ) {
        return latest as AtpSession;
    }

    await expireSession(session.did, body.error);
    throw new SessionExpiredError(session.did, body.error);
}

/**
 * A client assertion for refreshing. The server signs one only for the
 * issuer of whoever its own session says is signed in, so a server session
 * that ended or moved to another account refuses. While the access token
 * still works, the browser vouches for the account again and asks once more.
 * After that, only signing in again can help.
 */
async function assertionFor(
    session: AtpSession,
    key: DPoPKeyHandle,
): Promise<Record<string, string>> {
    try {
        return await clientAssertion(session.issuer);
    } catch (e) {
        if (!(e instanceof AssertionRefusedError)) {
            throw e;
        }
    }

    if (new Date(session.tokens.expiresAt).getTime() - Date.now() < 5_000) {
        await expireSession(session.did, 'server-session');
        throw new SessionExpiredError(session.did, 'server-session');
    }

    await vouchFor(
        {
            did: session.did,
            pds: session.pds,
            accessToken: session.tokens.accessToken,
        },
        key,
    );

    return clientAssertion(session.issuer);
}

/**
 * Start a server session for an account this browser holds tokens for.
 */
export async function startServerSession(did: string): Promise<void> {
    const session = await freshSession(did);
    const key = await getDpopKeyHandle(did).catch(() => null);

    if (!key) {
        await expireSession(did, 'key-missing');
        throw new SessionExpiredError(did, 'key-missing');
    }

    await vouchFor(
        { did, pds: session.pds, accessToken: session.tokens.accessToken },
        key,
    );
}

/**
 * Whether a PDS refused the access token itself, as opposed to the request.
 */
async function rejectsToken(response: Response): Promise<boolean> {
    if (response.status !== 401) {
        return false;
    }

    if (
        /error="invalid_token"/.test(
            response.headers.get('WWW-Authenticate') ?? '',
        )
    ) {
        return true;
    }

    const body = await response
        .clone()
        .json()
        .catch(() => null);

    return ['invalid_token', 'InvalidToken', 'ExpiredToken'].includes(
        body?.error,
    );
}

/**
 * A request to the account's own PDS with a DPoP-bound access token. A
 * rejected token is refreshed and the request sent once more. When the
 * session is dead it throws SessionExpiredError, and the request has not
 * taken effect.
 */
export async function pdsFetch(
    did: string,
    path: string,
    init: RequestInit = {},
): Promise<Response> {
    const session = await freshSession(did);
    const key = await getDpopKeyHandle(did).catch(() => null);

    if (!key) {
        await expireSession(did, 'key-missing');
        throw new SessionExpiredError(did, 'key-missing');
    }

    const url = `${session.pds}${path}`;
    const response = await dpopFetch(
        url,
        init,
        key,
        session.tokens.accessToken,
    );

    // A streamed body has been spent and cannot be sent again.
    if (
        !(await rejectsToken(response)) ||
        init.body instanceof ReadableStream
    ) {
        return response;
    }

    const refreshed = await refreshAfterRejection(
        did,
        session.tokens.accessToken,
    );
    const retried = await dpopFetch(
        url,
        init,
        key,
        refreshed.tokens.accessToken,
    );

    if (await rejectsToken(retried)) {
        await expireSession(did, 'token-rejected');
        throw new SessionExpiredError(did, 'token-rejected');
    }

    return retried;
}

/**
 * Revoke an account's refresh token at its authorization server, as best
 * it can. Signing out must not wait on a server that is down.
 */
export async function revokeSession(session: AtpSession): Promise<void> {
    if (!session.revocationEndpoint) {
        return;
    }

    try {
        const key = await getDpopKeyHandle(session.did);

        if (key) {
            await dpopFormPost(
                session.revocationEndpoint,
                {
                    ...(await clientAssertion(session.issuer)),
                    token: session.tokens.refreshToken,
                    token_type_hint: 'refresh_token',
                },
                key,
            );
        }
    } catch {
        // The tokens are forgotten here either way.
    }
}
