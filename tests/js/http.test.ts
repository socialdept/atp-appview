import { describe, expect, it } from 'vitest';
import { errorMessage } from '../../resources/js/http';

const json = (status: number, body: unknown) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

describe('errorMessage', () => {
    it('shows the message a refusal carries', async () => {
        expect(
            await errorMessage(
                json(403, { message: 'This account cannot sign in here.' }),
                'Could not start your session.',
            ),
        ).toBe('This account cannot sign in here.');
    });

    it('never shows a server error message, which can be an exception or a query', async () => {
        expect(
            await errorMessage(
                json(500, {
                    message:
                        'SQLSTATE[HY000]: General error: 1 table users has no column named did',
                }),
                'Could not start your session.',
            ),
        ).toBe('Could not start your session.');
    });

    it('falls back when the body has no message', async () => {
        expect(
            await errorMessage(new Response('not json', { status: 422 }), 'Fallback.'),
        ).toBe('Fallback.');
    });
});
