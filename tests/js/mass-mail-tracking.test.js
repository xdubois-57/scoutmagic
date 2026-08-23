// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so are the
// toast toolbox and window.location.reload (jsdom does not implement
// navigation). Exercises the REAL implementation in
// public/assets/js/mass-mail-tracking.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors modules/mass_mail/views/tracking.html.twig: the
// two filter controls, and three recipient rows whose `data-search`
// haystack the SERVER lowercased (which is why the file lowercases only
// the needle).
import { beforeEach, describe, expect, it, vi } from 'vitest';

function recipient(id, search, status, resendable) {
    return `
        <tr data-search="${search}" data-status="${status}">
            <td>${id}</td>
            <td>${resendable ? `<button type="button" class="mmt-resend-btn" data-id="${id}"></button>` : ''}</td>
        </tr>`;
}

const PAGE = `
    <input type="text" id="mmt-search" value="">
    <select id="mmt-status">
        <option value="" selected>Tous</option>
        <option value="sent">Envoyé</option>
        <option value="failed">Échec</option>
    </select>
    <table id="mmt-table"><tbody>
        ${recipient('1', 'jean dupont louveteaux jean@example.org', 'sent', false)}
        ${recipient('2', 'marie durand éclaireurs marie@example.org', 'failed', true)}
        ${recipient('3', 'paul dupont pionniers paul@example.org', 'failed', true)}
    </tbody></table>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('mass-mail-tracking.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/mass-mail/4/tracking', reload: vi.fn() },
        });
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/mass-mail-tracking.js');
    }

    const visibleIds = () => [...document.querySelectorAll('tbody tr[data-status]')]
        .filter((tr) => !tr.classList.contains('d-none'))
        .map((tr) => tr.cells[0].textContent);

    function search(value) {
        const input = document.getElementById('mmt-search');
        input.value = value;
        input.dispatchEvent(new Event('input'));
    }

    function status(value) {
        const select = document.getElementById('mmt-status');
        select.value = value;
        select.dispatchEvent(new Event('change'));
    }

    describe('entry guard', () => {
        it('does nothing at all on a page with no tracking table', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('filtering', () => {
        it('shows everything to begin with', async () => {
            await boot();

            expect(visibleIds()).toEqual(['1', '2', '3']);
        });

        it('matches anywhere in the row — name, section or address', async () => {
            await boot();

            search('dupont');
            expect(visibleIds()).toEqual(['1', '3']);

            search('pionniers');
            expect(visibleIds()).toEqual(['3']);

            search('marie@example.org');
            expect(visibleIds()).toEqual(['2']);
        });

        it('lowercases the needle — the haystack is already lowercase', async () => {
            await boot();
            search('DuPoNt');

            expect(visibleIds()).toEqual(['1', '3']);
        });

        it('ignores surrounding whitespace', async () => {
            await boot();
            search('   dupont  ');

            expect(visibleIds()).toEqual(['1', '3']);
        });

        it('filters by status on its own', async () => {
            await boot();
            status('failed');

            expect(visibleIds()).toEqual(['2', '3']);
        });

        it('applies both filters at once', async () => {
            await boot();
            search('dupont');
            status('failed');

            expect(visibleIds()).toEqual(['3']);
        });

        it('can hide every row — an empty table is a legitimate answer', async () => {
            await boot();
            search('personne');

            expect(visibleIds()).toEqual([]);
        });

        it('brings the rows back when the filter is cleared', async () => {
            await boot();
            search('dupont');
            search('');

            expect(visibleIds()).toEqual(['1', '2', '3']);
        });

        it('applies a filter the browser restored on reload, at once', async () => {
            document.getElementById('mmt-search').value = 'dupont';
            await boot();

            expect(visibleIds()).toEqual(['1', '3']);
        });
    });

    describe('resending', () => {
        it('POSTs to the recipient\'s own URL with the CSRF token', async () => {
            await boot();
            document.querySelector('.mmt-resend-btn[data-id="2"]').click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/mass-mail/recipients/2/resend');
            expect(opts.method).toBe('POST');
            expect(JSON.parse(opts.body)).toEqual({ _csrf_token: 'tok-123' });
        });

        it('reloads once the server confirms', async () => {
            await boot();
            document.querySelector('.mmt-resend-btn[data-id="2"]').click();

            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('re-enables the button and says why on a refusal answered with HTTP 200', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Adresse invalide.' }));
            await boot();
            const btn = document.querySelector('.mmt-resend-btn[data-id="2"]');
            btn.click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Adresse invalide.', { variant: 'error' });
            expect(btn.disabled).toBe(false);
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('does not leave the button dead when the network fails', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            const btn = document.querySelector('.mmt-resend-btn[data-id="3"]');
            btn.click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' });
            expect(btn.disabled).toBe(false);
        });
    });
});
