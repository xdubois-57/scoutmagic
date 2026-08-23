// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so is the
// site-wide toast toolbox. Exercises the REAL implementation in
// public/assets/js/registration-departures.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors modules/registration/views/departures.html.twig:
// one member already marked as leaving (comment row visible) and one not.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function member(id, leaving) {
    return `
        <tr>
            <td><input type="checkbox" class="departure-checkbox"
                       data-member-year-id="${id}" ${leaving ? 'checked' : ''}></td>
        </tr>
        <tr class="departure-comment-row" data-member-year-id="${id}"
            style="${leaving ? '' : 'display:none;'}">
            <td><textarea class="departure-comment" data-member-year-id="${id}"></textarea></td>
        </tr>`;
}

const PAGE = `<table><tbody>${member('31', false)}${member('32', true)}</tbody></table>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('registration-departures.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/registration-departures.js');
    }

    const box = (id) => document.querySelector(`.departure-checkbox[data-member-year-id="${id}"]`);
    const commentRow = (id) =>
        document.querySelector(`.departure-comment-row[data-member-year-id="${id}"]`);
    const comment = (id) => document.querySelector(`.departure-comment[data-member-year-id="${id}"]`);
    const lastRequest = () => {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, body: JSON.parse(opts.body) };
    };

    describe('entry guard', () => {
        it('does nothing at all on a page with no departure rows', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('ticking a departure', () => {
        it('POSTs to the member-year\'s own URL with the CSRF token', async () => {
            await boot();
            box('31').checked = true;
            box('31').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest()).toEqual({
                url: '/departs/31',
                body: { leaving: true, _csrf_token: 'tok-123' },
            });
        });

        it('reveals the comment row it governs', async () => {
            await boot();
            expect(commentRow('31').style.display).toBe('none');

            box('31').checked = true;
            box('31').dispatchEvent(new Event('change'));
            expect(commentRow('31').style.display).toBe('');
        });

        it('hides the comment row again when the departure is taken back', async () => {
            await boot();
            box('32').checked = false;
            box('32').dispatchEvent(new Event('change'));

            expect(commentRow('32').style.display).toBe('none');
            await vi.waitFor(() => expect(lastRequest().body.leaving).toBe(false));
        });

        it('says nothing on success — this is a list gone down one row at a time', async () => {
            await boot();
            box('31').checked = true;
            box('31').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        });

        it('PUTS THE BOX BACK when the server refuses — the screen never claims an unrecorded departure', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Année close.' }));
            await boot();
            box('31').checked = true;
            box('31').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(box('31').checked).toBe(false));
            expect(commentRow('31').style.display).toBe('none');
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Année close.', { variant: 'error' });
        });

        it('puts the box back on a network failure too, and says which kind it was', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            box('32').checked = false;
            box('32').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(box('32').checked).toBe(true));
            expect(commentRow('32').style.display).toBe('');
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' });
        });

        it('has a sentence of its own when a refusal carries none', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false }));
            await boot();
            box('31').checked = true;
            box('31').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith("Erreur lors de l'enregistrement.", { variant: 'error' });
        });
    });

    describe('the comment', () => {
        it('saves on blur, not on every keystroke', async () => {
            await boot();
            comment('32').value = 'Part en secondaire';
            comment('32').dispatchEvent(new Event('input'));
            expect(fetch).not.toHaveBeenCalled();

            comment('32').dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest()).toEqual({
                url: '/departs/32',
                body: { comment: 'Part en secondaire', _csrf_token: 'tok-123' },
            });
        });

        it('saves an emptied comment — clearing one is a change like any other', async () => {
            await boot();
            comment('32').value = '';
            comment('32').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body.comment).toBe('');
        });

        it('says why when the comment could not be saved', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Trop long.' }));
            await boot();
            comment('32').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Trop long.', { variant: 'error' });
        });
    });
});
