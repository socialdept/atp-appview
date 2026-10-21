import { describe, expect, it } from 'vitest';
import {
    authorLabels,
    badges,
    hiddenFromGuests,
    labelName,
    warnings,
} from '../../resources/js/moderation';
import { authorHandle, authorName } from '../../resources/js/views';

describe('moderation helpers', () => {
    const moderation = {
        warn: [{ src: 'did:plc:mod', val: 'graphic-media' }],
        badges: [{ src: 'did:plc:mod', val: 'satire' }],
        author: {
            warn: [{ src: 'did:plc:mod', val: 'spam' }],
            badges: [{ src: 'did:plc:mod', val: 'bot' }],
        },
    };

    it("keeps the content's own labels apart from its author's", () => {
        expect(warnings(moderation)).toEqual(moderation.warn);
        expect(badges(moderation)).toEqual(moderation.badges);
        expect(authorLabels(moderation).map((label) => label.val)).toEqual([
            'spam',
            'bot',
        ]);
        expect(warnings(null)).toEqual([]);
        expect(authorLabels(undefined)).toEqual([]);
    });

    it('tells a guest-only hide apart from any other', () => {
        expect(hiddenFromGuests({ hide: true, noUnauthenticated: true })).toBe(
            true,
        );
        expect(hiddenFromGuests({ hide: true })).toBe(false);
        expect(hiddenFromGuests(null)).toBe(false);
    });

    it('reads label values as words', () => {
        expect(labelName('graphic-media')).toBe('graphic media');
        expect(labelName('!no-unauthenticated')).toBe('no unauthenticated');
    });
});

describe('author helpers', () => {
    const author = {
        did: 'did:plc:alice',
        handle: 'alice.test',
        displayName: null,
    };

    it('names an author by display name, then handle, never by DID', () => {
        expect(authorName({ ...author, displayName: 'Alice' })).toBe('Alice');
        expect(authorName(author)).toBe('@alice.test');
        expect(authorName({ ...author, displayName: '', handle: null })).toBe(
            '@handle.invalid',
        );
        expect(authorHandle({ did: 'did:plc:alice', handle: null })).toBe(
            '@handle.invalid',
        );
    });
});
