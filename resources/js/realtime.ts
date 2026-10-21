import { configured } from './config';
import { procedure } from './xrpc';

/**
 * Realtime with Laravel Echo, for the app's realtime feature. Pings say
 * what changed, never what it holds: fetch it through the app's own
 * methods, as the viewer may see it.
 *
 *     const echo = new Echo({ broadcaster: 'reverb', ..., authorizer: realtimeAuthorizer });
 *
 *     echo.channel(collectionChannel('com.example.post'))
 *         .listen('.ping', (ping: Ping) => showNewPosts(ping.count));
 *     echo.channel(await subjectChannel(postUri)).listen('.ping', reloadCounts);
 *     echo.private(await accountChannel(did)).listen('.ping', refreshUnread);
 *
 * Channel names hash URIs and DIDs, as the server does, since broadcast
 * channel names cannot hold their colons and slashes.
 */

export interface Ping {
    kind: 'collection' | 'subject' | 'account';
    collection: string | null;
    uri: string | null;
    action: 'create' | 'update' | 'delete' | 'notification' | null;
    // How many changes the ping stands for, gathered over the window.
    count: number;
}

/**
 * The first 32 hex characters of a value's SHA-256, as the server hashes it.
 */
export async function channelHash(value: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(value),
    );

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('')
        .slice(0, 32);
}

/**
 * New, changed and deleted records of a collection. Public.
 */
export function collectionChannel(nsid: string): string {
    return `appview.collection.${nsid}`;
}

/**
 * Records pointing at this one, such as likes and replies. Public.
 */
export async function subjectChannel(uri: string): Promise<string> {
    return `appview.subject.${await channelHash(uri)}`;
}

/**
 * The account's notifications. Private: join it with echo.private(), which
 * adds the private- prefix, and the realtime authorizer.
 */
export async function accountChannel(did: string): Promise<string> {
    return `appview.account.${await channelHash(did)}`;
}

type AuthCallback = (
    error: Error | null,
    data: { auth: string } | null,
) => void;

/**
 * Echo's authorizer for private channels, through the app's authorize
 * procedure: same origin with a Laravel session, or through the account's
 * PDS with a service auth token, as the xrpc helper decides.
 */
export function realtimeAuthorizer(channel: { name: string }) {
    return {
        authorize(socketId: string, callback: AuthCallback): void {
            const method = configured()?.realtime?.authorize;

            if (!method) {
                callback(
                    new Error('The app has no realtime authorize method.'),
                    null,
                );

                return;
            }

            procedure<{ auth: string }>(method, {
                socket_id: socketId,
                channel_name: channel.name,
            }).then(
                (data) => callback(null, data),
                (error: unknown) =>
                    callback(
                        error instanceof Error ? error : new Error(String(error)),
                        null,
                    ),
            );
        },
    };
}
