import { configured } from './config';
import { procedure } from './xrpc';

/**
 * Web Push in this browser, for the app's push feature: register the app's
 * service worker, subscribe with the app's VAPID key, and hand the
 * subscription to the server through its subscribe procedure.
 *
 * The service worker is the app's own. It receives each payload in its
 * push event and shows it:
 *
 *     self.addEventListener('push', (event) => {
 *         const data = event.data?.json() ?? {};
 *         event.waitUntil(self.registration.showNotification('Title', { body: '…', data }));
 *     });
 */

export type PushState = 'unsupported' | 'off' | 'on' | 'blocked';

function settings() {
    const push = configured()?.push;

    return push?.publicKey && push.subscribe && push.unsubscribe ? push : null;
}

/**
 * Whether this browser can take push, and the app offers it.
 */
export function pushSupported(): boolean {
    return (
        settings() !== null &&
        typeof navigator !== 'undefined' &&
        'serviceWorker' in navigator &&
        typeof window !== 'undefined' &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * Where push stands in this browser.
 */
export async function pushState(workerUrl = '/sw.js'): Promise<PushState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    const registration = await navigator.serviceWorker.getRegistration(workerUrl);
    const subscription = await registration?.pushManager.getSubscription();

    return subscription ? 'on' : 'off';
}

/**
 * Turn push on: register the worker, ask permission, subscribe and tell
 * the server. Resolves to the state it ends in, 'blocked' when the person
 * said no.
 */
export async function enablePush(workerUrl = '/sw.js'): Promise<PushState> {
    const push = settings();

    if (!push || !pushSupported()) {
        return 'unsupported';
    }

    if ((await Notification.requestPermission()) !== 'granted') {
        return 'blocked';
    }

    const registration = await navigator.serviceWorker.register(workerUrl);
    await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: fromBase64url(push.publicKey),
        }));

    await procedure(push.subscribe!, subscription.toJSON() as Record<string, unknown>);

    return 'on';
}

/**
 * Turn push off in this browser, here and on the server.
 */
export async function disablePush(workerUrl = '/sw.js'): Promise<PushState> {
    const push = settings();

    if (!push || !pushSupported()) {
        return 'unsupported';
    }

    const registration = await navigator.serviceWorker.getRegistration(workerUrl);
    const subscription = await registration?.pushManager.getSubscription();

    if (subscription) {
        await procedure(push.unsubscribe!, { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
    }

    return 'off';
}

/**
 * A base64url string as the bytes PushManager wants for its key.
 */
export function fromBase64url(value: string): Uint8Array<ArrayBuffer> {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64 + '='.repeat((4 - (base64.length % 4)) % 4));
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}
