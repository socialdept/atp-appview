/**
 * What the Moderation view part says about a piece of content, and helpers
 * for showing it. Anything false or empty is left out of the part's output.
 */
export type NamedLabel = { src: string; val: string };

export type Moderation = {
    hide?: boolean;
    review?: boolean;
    ban?: boolean;
    warn?: NamedLabel[];
    blur?: 'media' | 'content';
    badges?: NamedLabel[];
    noUnauthenticated?: boolean;
    author?: Omit<Moderation, 'author'>;
};

/**
 * The content's own warnings: these cover it. An author's account labels
 * belong to their profile, so they never cover what they wrote.
 */
export function warnings(
    moderation: Moderation | null | undefined,
): NamedLabel[] {
    return moderation?.warn ?? [];
}

export function badges(
    moderation: Moderation | null | undefined,
): NamedLabel[] {
    return moderation?.badges ?? [];
}

/**
 * Everything the author's account is labeled with, warnings and badges.
 */
export function authorLabels(
    moderation: Moderation | null | undefined,
): NamedLabel[] {
    return [
        ...(moderation?.author?.warn ?? []),
        ...(moderation?.author?.badges ?? []),
    ];
}

/**
 * Whether the content is hidden from this viewer only because its author
 * asked not to be shown to signed-out visitors, so a sign-in prompt fits.
 */
export function hiddenFromGuests(
    moderation: Moderation | null | undefined,
): boolean {
    return !!moderation?.hide && !!moderation?.noUnauthenticated;
}

/**
 * A label's value as words: graphic-media reads graphic media.
 */
export function labelName(val: string): string {
    return val.replace(/^!/, '').replace(/-/g, ' ');
}
