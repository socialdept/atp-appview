import { beforeEach, describe, expect, it, vi } from 'vitest';

const procedure = vi.fn();

vi.mock('../../resources/js/xrpc', () => ({
    procedure: (...args: unknown[]) => procedure(...args),
}));

const { configureAtproto } = await import('../../resources/js/config');
const {
    accountChannel,
    channelHash,
    collectionChannel,
    realtimeAuthorizer,
    subjectChannel,
} = await import('../../resources/js/realtime');

function configure(realtime: { authorize: string } | null): void {
    configureAtproto({
        sessionMethod: null,
        audience: null,
        realtime,
        routes: {} as never,
    });
}

beforeEach(() => {
    procedure.mockReset();
    configure({ authorize: 'com.example.realtime.authorize' });
});

describe('channel names', () => {
    it('hash as the server does, since channel names cannot hold colons or slashes', async () => {
        // PHP: substr(hash('sha256', $value), 0, 32)
        expect(await channelHash('at://did:plc:alice/com.example.post/p1')).toBe('60ed8d35fd67e4ef63dc072419ec7a29');
        expect(await subjectChannel('at://did:plc:alice/com.example.post/p1')).toBe('appview.subject.60ed8d35fd67e4ef63dc072419ec7a29');
        expect(await accountChannel('did:plc:alice')).toBe('appview.account.a304c5c525080ebfa7b6205c6c31508c');
        expect(collectionChannel('com.example.post')).toBe('appview.collection.com.example.post');
    });
});

describe('realtimeAuthorizer', () => {
    it('asks the authorize procedure, however the xrpc helper reaches it', async () => {
        procedure.mockResolvedValue({ auth: 'key:signature' });
        const callback = vi.fn();

        realtimeAuthorizer({ name: 'private-appview.account.abc' }).authorize('123.456', callback);
        await vi.waitFor(() => expect(callback).toHaveBeenCalled());

        expect(procedure).toHaveBeenCalledWith('com.example.realtime.authorize', {
            socket_id: '123.456',
            channel_name: 'private-appview.account.abc',
        });
        expect(callback).toHaveBeenCalledWith(null, { auth: 'key:signature' });
    });

    it('passes a refusal on, and refuses without an authorize method', async () => {
        procedure.mockRejectedValue(new Error('Forbidden'));
        const refused = vi.fn();
        realtimeAuthorizer({ name: 'private-appview.account.abc' }).authorize('1.2', refused);
        await vi.waitFor(() => expect(refused).toHaveBeenCalled());
        expect(refused.mock.calls[0][0]).toBeInstanceOf(Error);
        expect(refused.mock.calls[0][1]).toBeNull();

        configure(null);
        const missing = vi.fn();
        realtimeAuthorizer({ name: 'private-appview.account.abc' }).authorize('1.2', missing);
        expect(missing.mock.calls[0][0]).toBeInstanceOf(Error);
        expect(procedure).toHaveBeenCalledTimes(1);
    });
});
