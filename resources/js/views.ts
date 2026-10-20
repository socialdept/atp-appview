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
 * What to call an account: its display name, or its handle, or its DID when
 * it has neither. Profiles leave displayName empty rather than guess.
 */
export function authorName(
    author: Pick<Author, 'did' | 'handle' | 'displayName'>,
): string {
    return author.displayName ?? authorHandle(author);
}

/**
 * The handle as people write it, or the DID when the index has none.
 */
export function authorHandle(author: Pick<Author, 'did' | 'handle'>): string {
    return author.handle ? `@${author.handle}` : author.did;
}
