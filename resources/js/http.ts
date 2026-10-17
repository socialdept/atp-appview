import { config } from './config';

/**
 * Same-origin requests to this app, carrying Laravel's CSRF token.
 */
function xsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

export async function appFetch(
    url: string,
    init: RequestInit = {},
): Promise<Response> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-XSRF-TOKEN', xsrfToken());

    if (init.body && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    return fetch(url, { ...init, headers, credentials: 'same-origin' });
}

/**
 * The message a JSON error response carries, or a fallback.
 */
export async function errorMessage(
    response: Response,
    fallback: string,
): Promise<string> {
    const body = await response.json().catch(() => null);

    return typeof body?.message === 'string' ? body.message : fallback;
}

/**
 * The server would not sign a client assertion for that authorization
 * server: no sign-in is in progress and no one is signed in who uses it.
 */
export class AssertionRefusedError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'AssertionRefusedError';
    }
}

/**
 * A client assertion for one authorization server. The server signs it so
 * the app stays a confidential client, and only for the sign-in in progress
 * or the signed-in user's own server.
 */
export async function clientAssertion(
    issuer: string,
): Promise<Record<string, string>> {
    const response = await appFetch(config().routes.clientAssertion.url(), {
        method: 'POST',
        body: JSON.stringify({ issuer }),
    });

    if (response.status === 403) {
        throw new AssertionRefusedError(
            await errorMessage(response, 'Sign in again to continue.'),
        );
    }

    if (!response.ok) {
        throw new Error(
            await errorMessage(response, 'Could not authenticate the app.'),
        );
    }

    return response.json();
}
