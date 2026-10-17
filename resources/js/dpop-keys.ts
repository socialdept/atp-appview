/**
 * DPoP key pairs for frontend-mode sessions.
 *
 * Each private key is a non-extractable WebCrypto CryptoKey: JavaScript can
 * sign with it but never read it, and it is stored in IndexedDB, never in
 * localStorage. A key is held under the OAuth state during sign-in and moved
 * to the account's DID once the tokens arrive.
 */
import { storageName } from './config';

const DB_NAME = () => storageName('dpop');
const DB_VERSION = 1;
const STORE_NAME = 'keypairs';

export interface DPoPKeyHandle {
    privateKey: CryptoKey;
    publicJwk: JsonWebKey;
}

interface StoredKeyPair {
    did: string;
    privateKey: CryptoKey;
    publicKey: CryptoKey;
    publicJwk: JsonWebKey;
}

function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME(), DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'did' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * Generate a P-256 key pair with a non-extractable private key.
 *
 * The private key cannot be read by JavaScript. It can only be used
 * for signing via `crypto.subtle.sign()`. The public key is extractable
 * so it can be included in DPoP proof headers.
 */
export async function generateKeyPair(): Promise<{
    privateKey: CryptoKey;
    publicKey: CryptoKey;
    publicJwk: JsonWebKey;
}> {
    const keyPair = await crypto.subtle.generateKey(
        { name: 'ECDSA', namedCurve: 'P-256' },
        false,
        ['sign', 'verify'],
    );

    const publicJwk = await crypto.subtle.exportKey('jwk', keyPair.publicKey);

    return {
        privateKey: keyPair.privateKey,
        publicKey: keyPair.publicKey,
        publicJwk,
    };
}

/**
 * Store a key pair in IndexedDB keyed by DID.
 */
export async function storeKeyPair(
    did: string,
    keys: {
        privateKey: CryptoKey;
        publicKey: CryptoKey;
        publicJwk: JsonWebKey;
    },
): Promise<void> {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);

        const entry: StoredKeyPair = {
            did,
            privateKey: keys.privateKey,
            publicKey: keys.publicKey,
            publicJwk: keys.publicJwk,
        };

        const request = store.put(entry);
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}

/**
 * Retrieve a key pair from IndexedDB by DID.
 */
export async function getKeyPair(did: string): Promise<StoredKeyPair | null> {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const store = tx.objectStore(STORE_NAME);
        const request = store.get(did);

        request.onsuccess = () => resolve(request.result ?? null);
        request.onerror = () => reject(request.error);
    });
}

/**
 * Delete a key pair from IndexedDB. Used during sign-out.
 */
export async function deleteKeyPair(did: string): Promise<void> {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const request = store.delete(did);

        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}

/**
 * Delete all key pairs from IndexedDB. Used during sign-out-all.
 */
export async function deleteAllKeyPairs(): Promise<void> {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const request = store.clear();

        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}

/**
 * Re-key a stored key pair from one identifier to another.
 *
 * Used after token exchange to move a key pair from its temporary
 * state-based key (e.g. "oauth_state:abc") to the permanent DID-based key.
 */
export async function reKeyPair(oldKey: string, newKey: string): Promise<void> {
    const entry = await getKeyPair(oldKey);

    if (!entry) {
        return;
    }

    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);

        // Store under new key
        const newEntry: StoredKeyPair = {
            did: newKey,
            privateKey: entry.privateKey,
            publicKey: entry.publicKey,
            publicJwk: entry.publicJwk,
        };

        store.put(newEntry);
        store.delete(oldKey);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

/**
 * Get a DPoP key handle for signing proofs. Returns null if no key
 * is stored for the given DID.
 */
export async function getDpopKeyHandle(
    did: string,
): Promise<DPoPKeyHandle | null> {
    const entry = await getKeyPair(did);

    if (!entry) {
        return null;
    }

    return {
        privateKey: entry.privateKey,
        publicJwk: entry.publicJwk,
    };
}
