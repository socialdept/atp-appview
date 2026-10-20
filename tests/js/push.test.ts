import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const procedure = vi.fn(async (..._args: unknown[]) => ({}));

vi.mock('../../resources/js/xrpc', () => ({
    procedure: (...args: unknown[]) => procedure(...args),
}));

const { configureAtproto } = await import('../../resources/js/config');
const { disablePush, enablePush, fromBase64url, pushState, pushSupported } =
    await import('../../resources/js/push');

const PUBLIC_KEY = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

let permission: NotificationPermission;
let current: { endpoint: string; toJSON: () => unknown; unsubscribe: () => Promise<boolean> } | null;
const subscribe = vi.fn();

function configure(push: unknown): void {
    configureAtproto({
        routes: {} as never,
        sessionMethod: null,
        audience: null,
        push: push as never,
    });
}

beforeEach(() => {
    permission = 'default';
    current = null;
    procedure.mockClear();
    subscribe.mockReset();
    subscribe.mockImplementation(async () => {
        current = {
            endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
            toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'p', auth: 'a' } }),
            unsubscribe: vi.fn(async () => true),
        };
        return current;
    });

    const registration = {
        pushManager: { getSubscription: async () => current, subscribe },
    };

    vi.stubGlobal('window', { PushManager: class {}, Notification: class {} });
    vi.stubGlobal('Notification', {
        get permission() {
            return permission;
        },
        requestPermission: vi.fn(async () => permission),
    });
    vi.stubGlobal('navigator', {
        serviceWorker: {
            register: vi.fn(async () => registration),
            getRegistration: vi.fn(async () => registration),
            ready: Promise.resolve(registration),
        },
    });

    configure({ publicKey: PUBLIC_KEY, subscribe: 'com.example.push.subscribe', unsubscribe: 'com.example.push.unsubscribe' });
});

afterEach(() => vi.unstubAllGlobals());

describe('push', () => {
    it('subscribes with the app key and hands the subscription to the server', async () => {
        permission = 'granted';

        expect(await enablePush()).toBe('on');

        expect(Array.from(subscribe.mock.calls[0][0].applicationServerKey)).toEqual(Array.from(fromBase64url(PUBLIC_KEY)));
        expect(subscribe.mock.calls[0][0].userVisibleOnly).toBe(true);
        expect(procedure).toHaveBeenCalledWith('com.example.push.subscribe', {
            endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
            keys: { p256dh: 'p', auth: 'a' },
        });
        expect(await pushState()).toBe('on');
    });

    it('stops when the person says no', async () => {
        permission = 'denied';

        expect(await enablePush()).toBe('blocked');
        expect(subscribe).not.toHaveBeenCalled();
        expect(procedure).not.toHaveBeenCalled();
        expect(await pushState()).toBe('blocked');
    });

    it('unsubscribes here and on the server', async () => {
        permission = 'granted';
        await enablePush();
        const subscription = current!;

        expect(await disablePush()).toBe('off');
        expect(procedure).toHaveBeenLastCalledWith('com.example.push.unsubscribe', { endpoint: subscription.endpoint });
        expect(subscription.unsubscribe).toHaveBeenCalled();
    });

    it('is unsupported when the app offers no push', async () => {
        configure(null);

        expect(pushSupported()).toBe(false);
        expect(await enablePush()).toBe('unsupported');
    });

    it('decodes the key to the 65 bytes of a P-256 point', () => {
        const key = fromBase64url(PUBLIC_KEY);

        expect(key.length).toBe(65);
        expect(key[0]).toBe(4);
    });
});
