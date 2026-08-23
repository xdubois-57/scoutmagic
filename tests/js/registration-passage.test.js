// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked. Exercises the REAL
// implementation in public/assets/js/registration-passage.js (imported
// below, never reimplemented here). That file is an IIFE that reads the
// DOM at import time, so each test builds its fixture first and then
// imports the module via vi.resetModules() + await import().
//
// The fixture mirrors modules/registration/views/passage.html.twig: two
// rows from the two different tables of that page — a future member's
// intended section, and a current member's destination section — which
// carry different endpoints and different field names in data-*, and go
// through the same handler.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function row(endpoint, field, id) {
    return `
        <tr><td>
            <select class="passage-select" data-endpoint="${endpoint}" data-field="${field}">
                <option value="">—</option>
                <option value="${id}" selected>Louveteaux</option>
            </select>
            <button type="button" class="passage-save">Enregistrer</button>
            <div class="form-text passage-feedback" role="status" aria-live="polite"></div>
        </td></tr>`;
}

const PAGE = `<table><tbody>
    ${row('/passage/inscription/12/section', 'intended_section_id', 4)}
    ${row('/passage/membre/77/destination', 'destination_section_id', 9)}
</tbody></table>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('registration-passage.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/registration-passage.js');
    }

    const cells = () => document.querySelectorAll('td');
    const cell = (i) => cells()[i];
    const saveIn = (i) => cell(i).querySelector('.passage-save');
    const feedbackIn = (i) => cell(i).querySelector('.passage-feedback');
    const lastRequest = () => {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, body: JSON.parse(opts.body) };
    };

    describe('entry guard', () => {
        it('does nothing at all on a page with no passage rows', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('saving', () => {
        it('posts each row to ITS OWN endpoint under ITS OWN field name', async () => {
            await boot();

            saveIn(0).click();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
            expect(lastRequest()).toEqual({
                url: '/passage/inscription/12/section',
                body: { intended_section_id: 4, _csrf_token: 'tok-123' },
            });

            saveIn(1).click();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(lastRequest()).toEqual({
                url: '/passage/membre/77/destination',
                body: { destination_section_id: 9, _csrf_token: 'tok-123' },
            });
        });

        it('says « Enregistrement… » while it waits, then « Enregistré. »', async () => {
            let settle;
            global.fetch = vi.fn(() => new Promise((resolve) => { settle = resolve; }));
            await boot();
            saveIn(0).click();

            expect(feedbackIn(0).textContent).toBe('Enregistrement…');
            expect(saveIn(0).disabled).toBe(true);

            // withDisabled() defers the call itself by a microtask.
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            settle({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) });
            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Enregistré.'));
            expect(feedbackIn(0).classList.contains('text-success')).toBe(true);
            expect(feedbackIn(0).classList.contains('text-danger')).toBe(false);
            expect(saveIn(0).disabled).toBe(false);
        });

        it('answers in the row that asked, and leaves the other one alone', async () => {
            await boot();
            saveIn(1).click();

            await vi.waitFor(() => expect(feedbackIn(1).textContent).toBe('Enregistré.'));
            expect(feedbackIn(0).textContent).toBe('');
        });

        it('shows the server\'s own message on a refusal answered with HTTP 200', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Section pleine.' }));
            await boot();
            saveIn(0).click();

            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Section pleine.'));
            expect(feedbackIn(0).classList.contains('text-danger')).toBe(true);
        });

        it('has a sentence of its own when the refusal carries none', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false }));
            await boot();
            saveIn(0).click();

            await vi.waitFor(() =>
                expect(feedbackIn(0).textContent).toBe("Erreur lors de l'enregistrement."));
        });

        it('distinguishes a network failure from a refusal', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            saveIn(0).click();

            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Erreur réseau.'));
            expect(feedbackIn(0).classList.contains('text-danger')).toBe(true);
            expect(saveIn(0).disabled).toBe(false);
        });

        it('re-enables the button after a failure so the save can be retried', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
            await boot();
            saveIn(0).click();
            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Non.'));

            global.fetch = vi.fn(() => jsonResponse({ success: true }));
            saveIn(0).click();
            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Enregistré.'));
        });
    });

    describe('changing the pick', () => {
        it('clears a stale « Enregistré. » so it never describes the new choice', async () => {
            await boot();
            saveIn(0).click();
            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Enregistré.'));

            cell(0).querySelector('.passage-select').dispatchEvent(new Event('change'));
            expect(feedbackIn(0).textContent).toBe('');
            expect(feedbackIn(0).classList.contains('text-danger')).toBe(false);
        });

        it('clears a stale error too, and sends nothing on its own', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
            await boot();
            saveIn(0).click();
            await vi.waitFor(() => expect(feedbackIn(0).textContent).toBe('Non.'));

            fetch.mockClear();
            cell(0).querySelector('.passage-select').dispatchEvent(new Event('change'));
            expect(feedbackIn(0).textContent).toBe('');
            expect(fetch).not.toHaveBeenCalled();
        });
    });
});
