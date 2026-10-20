import { configured } from './config';
import { withSession } from './reconnect';
import { activeDid, pdsFetch } from './session';
import { procedure } from './xrpc';

/**
 * Writing records the frontend-mode way: the browser writes to the signed-in
 * account's own PDS, then asks this AppView to index the record now, through
 * the sync procedure, rather than when Tap delivers it. The ask goes through
 * the xrpc helper, so it takes the same origin or the PDS as it can.
 *
 * Each step runs in withSession() on its own, so a dead session asks the user
 * to sign back in and only the step that never happened runs again.
 *
 *     const { uri } = await createRecord('xyz.statusphere.status', {
 *         status: '🦋',
 *         createdAt: new Date().toISOString(),
 *     });
 */
export class RecordWriteError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
        this.name = 'RecordWriteError';
    }
}

export interface WriteOptions {
    // A record key, for collections keyed by something other than a TID.
    rkey?: string;
    // Ask the AppView to index the record now. On by default when a sync
    // procedure is configured.
    sync?: boolean;
}

export async function createRecord(
    collection: string,
    record: Record<string, unknown>,
    options: WriteOptions = {},
): Promise<{ uri: string; cid: string }> {
    const did = signedIn();
    const written = await answer<{ uri: string; cid: string }>(
        await withSession(() =>
            pdsFetch(did, '/xrpc/com.atproto.repo.createRecord', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    repo: did,
                    collection,
                    rkey: options.rkey,
                    record: { $type: collection, ...record },
                }),
            }),
        ),
        'Your PDS would not save the record.',
    );

    await sync(written.uri, options);

    return written;
}

/**
 * Delete one of the signed-in account's records, then have the AppView drop
 * it from the index now.
 */
export async function deleteRecord(
    uri: string,
    options: Pick<WriteOptions, 'sync'> = {},
): Promise<void> {
    const did = signedIn();
    const [, repo, collection, rkey] =
        uri.match(/^at:\/\/([^/]+)\/([^/]+)\/([^/]+)$/) ?? [];

    if (repo !== did) {
        throw new RecordWriteError(
            'Only your own records can be deleted.',
            403,
        );
    }

    await answer(
        await withSession(() =>
            pdsFetch(did, '/xrpc/com.atproto.repo.deleteRecord', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ repo: did, collection, rkey }),
            }),
        ),
        'Your PDS would not delete the record.',
    );

    await sync(uri, options);
}

function signedIn(): string {
    const did = activeDid();

    if (!did) {
        throw new RecordWriteError('Sign in to write records.', 401);
    }

    return did;
}

async function sync(uri: string, options: WriteOptions): Promise<void> {
    const nsid = configured()?.syncRecord;

    if (options.sync === false || !nsid) {
        return;
    }

    await withSession(() => procedure(nsid, { uri }));
}

async function answer<T = unknown>(
    response: Response,
    fallback: string,
): Promise<T> {
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new RecordWriteError(
            typeof body?.message === 'string' && body.message
                ? `${fallback} ${body.message}`
                : fallback,
            response.status,
        );
    }

    return body as T;
}
