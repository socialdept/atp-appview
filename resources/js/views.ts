/**
 * An account as the Author view part embeds it.
 */
export type Author = {
    did: string;
    handle: string | null;
    displayName: string | null;
    avatar: string | null;
};

/**
 * What an account without a valid handle shows as, as Bluesky has it. A DID
 * is never shown in a name's place.
 */
export const INVALID_HANDLE = '@handle.invalid';

/**
 * What to call an account: its display name, else its handle, else
 * @handle.invalid. Profiles leave displayName empty rather than guess.
 */
export function authorName(
    author: Pick<Author, 'did' | 'handle' | 'displayName'>,
): string {
    return author.displayName || authorHandle(author);
}

/**
 * The handle as people write it, or @handle.invalid when the index has none.
 */
export function authorHandle(author: Pick<Author, 'did' | 'handle'>): string {
    return author.handle ? `@${author.handle}` : INVALID_HANDLE;
}
