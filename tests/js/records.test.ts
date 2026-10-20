import { beforeEach, describe, expect, it, vi } from 'vitest';

let did: string | null = 'did:plc:alice';
const pdsFetch = vi.fn();
const procedure = vi.fn(async (..._args: unknown[]) => ({ indexed: true }));

vi.mock('../../resources/js/session', () => ({
    activeDid: () => did,
    pdsFetch: (...args: unknown[]) => pdsFetch(...args),
}));

vi.mock('../../resources/js/reconnect', () => ({
    withSession: <T>(action: () => Promise<T>) => action(),
}));

vi.mock('../../resources/js/xrpc', () => ({
    procedure: (...args: unknown[]) => procedure(...args),
}));

const { configureAtproto } = await import('../../resources/js/config');
const { createRecord, deleteRecord, RecordWriteError } = await import(
    '../../resources/js/records'
);

const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status });

function configure(syncRecord: string | null): void {
    configureAtproto({
        routes: {} as never,
        sessionMethod: null,
        audience: null,
        syncRecord,
    });
}

beforeEach(() => {
    did = 'did:plc:alice';
    pdsFetch.mockReset();
    procedure.mockClear();
    configure('com.example.syncRecord');
});

describe('createRecord', () => {
    it('writes to the PDS with its type, then has the AppView index it', async () => {
        pdsFetch.mockResolvedValue(
            json({ uri: 'at://did:plc:alice/xyz.status/3abc', cid: 'bafy' }),
        );

        const written = await createRecord('xyz.status', { status: '🦋' });

        expect(written).toEqual({
            uri: 'at://did:plc:alice/xyz.status/3abc',
            cid: 'bafy',
        });
        const [repo, path, init] = pdsFetch.mock.calls[0];
        expect([repo, path]).toEqual([
            'did:plc:alice',
            '/xrpc/com.atproto.repo.createRecord',
        ]);
        expect(JSON.parse(init.body)).toEqual({
            repo: 'did:plc:alice',
            collection: 'xyz.status',
            record: { $type: 'xyz.status', status: '🦋' },
        });
        expect(procedure).toHaveBeenCalledWith('com.example.syncRecord', {
            uri: 'at://did:plc:alice/xyz.status/3abc',
        });
    });

    it('skips the sync when asked to, or when there is no sync procedure', async () => {
        pdsFetch.mockResolvedValue(
            json({ uri: 'at://did:plc:alice/x/1', cid: 'c' }),
        );

        await createRecord('x', {}, { sync: false });
        configure(null);
        await createRecord('x', {});

        expect(procedure).not.toHaveBeenCalled();
    });

    it('passes a record key through', async () => {
        pdsFetch.mockResolvedValue(
            json({ uri: 'at://did:plc:alice/x/self', cid: 'c' }),
        );

        await createRecord('x', {}, { rkey: 'self' });

        expect(JSON.parse(pdsFetch.mock.calls[0][2].body).rkey).toBe('self');
    });

    it('throws what the PDS said, and nothing is synced', async () => {
        pdsFetch.mockResolvedValue(
            json(
                { error: 'InvalidRecord', message: 'Status is too long.' },
                400,
            ),
        );

        const error = await createRecord('x', {}).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(RecordWriteError);
        expect(error).toMatchObject({
            message: 'Your PDS would not save the record. Status is too long.',
            status: 400,
        });
        expect(procedure).not.toHaveBeenCalled();
    });

    it('needs someone signed in', async () => {
        did = null;

        await expect(createRecord('x', {})).rejects.toMatchObject({
            status: 401,
        });
        expect(pdsFetch).not.toHaveBeenCalled();
    });
});

describe('deleteRecord', () => {
    it("deletes the account's own record, then syncs it away", async () => {
        pdsFetch.mockResolvedValue(json({}));

        await deleteRecord('at://did:plc:alice/xyz.status/3abc');

        expect(JSON.parse(pdsFetch.mock.calls[0][2].body)).toEqual({
            repo: 'did:plc:alice',
            collection: 'xyz.status',
            rkey: '3abc',
        });
        expect(procedure).toHaveBeenCalledWith('com.example.syncRecord', {
            uri: 'at://did:plc:alice/xyz.status/3abc',
        });
    });

    it("refuses someone else's record", async () => {
        await expect(
            deleteRecord('at://did:plc:bob/xyz.status/3abc'),
        ).rejects.toMatchObject({ status: 403 });
        expect(pdsFetch).not.toHaveBeenCalled();
    });
});
