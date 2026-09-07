// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked. Exercises the REAL
// implementation in public/assets/js/presences-search.js (imported below,
// never reimplemented here). That file is an IIFE reading the DOM at
// import time, so each test builds its fixture first and then imports it
// via vi.resetModules() + await import().
//
// The fixture mirrors the search block of
// modules/presences/views/index.html.twig.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <div id="presences-search" data-section-id="4">
        <input type="text" id="presences-search-input">
        <div class="list-group d-none" id="presences-search-results"></div>
    </div>`;

const ANSWER = {
    success: true,
    events: [
        { url: '/chefs/presences/feuille/7', title: 'Réunion', date: '2026-09-13', pointed: true, rate: 88 },
        { url: '/chefs/presences/feuille/8', title: 'Fête de Noël', date: '2026-12-13', pointed: false, rate: 0 },
    ],
    animes: [
        { url: '/chefs/presences/anime/11', name: 'Hargot, Basile', rate: 21, tone: 'danger' },
    ],
};

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('presences-search.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.useRealTimers();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse(ANSWER));
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/presences-search.js');
    }

    const input = () => document.getElementById('presences-search-input');
    const panel = () => document.getElementById('presences-search-results');
    const rows = () => Array.from(panel().querySelectorAll('a'));
    const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
    const lastUrl = () => fetch.mock.calls[fetch.mock.calls.length - 1][0];

    describe('entry guard', () => {
        it('does nothing at all on a page with no search field', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    it('already answers on focus, before a single keystroke', async () => {
        await boot();

        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(lastUrl()).toBe('/chefs/presences/recherche?section=4&q=');
        expect(panel().classList.contains('d-none')).toBe(false);
    });

    it('asks the server rather than filtering a list in the browser', async () => {
        vi.useFakeTimers();
        await boot();

        input().value = 'hargot';
        input().dispatchEvent(new Event('input'));
        await vi.runAllTimersAsync();

        expect(lastUrl()).toBe('/chefs/presences/recherche?section=4&q=hargot');
    });

    it('groups the results by nature rather than mixing them', async () => {
        await boot();
        input().dispatchEvent(new Event('focus'));
        await flush();

        const headings = Array.from(panel().querySelectorAll('p')).map((p) => p.textContent);
        expect(headings).toEqual(['Évènements', 'Animés']);
        expect(rows().map((a) => a.getAttribute('href'))).toEqual([
            '/chefs/presences/feuille/7',
            '/chefs/presences/feuille/8',
            '/chefs/presences/anime/11',
        ]);
    });

    it('shows a stored date the way the screen writes it', async () => {
        await boot();
        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(rows()[0].textContent).toContain('13/09/2026');
    });

    it('says an evening is unpointed rather than calling it 0 %', async () => {
        await boot();
        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(rows()[1].textContent).toContain('non pointé');
        expect(rows()[1].textContent).not.toContain('0 %');
    });

    it('colours an animé by their rate', async () => {
        await boot();
        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(rows()[2].querySelector('.text-danger')).not.toBeNull();
    });

    it('renders a name as text, never as markup', async () => {
        global.fetch = vi.fn(() => jsonResponse({
            success: true,
            events: [],
            animes: [{ url: '/x', name: '<img src=x onerror=alert(1)>', rate: 0, tone: 'danger' }],
        }));
        await boot();

        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(panel().querySelector('img')).toBeNull();
        expect(rows()[0].textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('says so rather than showing an empty panel when nothing matches', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, events: [], animes: [] }));
        await boot();

        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(panel().textContent).toContain('Aucun résultat.');
    });

    it('closes rather than claiming a result when the server refuses', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Pas la vôtre.' }, 403));
        await boot();

        input().dispatchEvent(new Event('focus'));
        await flush();

        expect(panel().classList.contains('d-none')).toBe(true);
    });

    it('closes on Escape and on a click outside', async () => {
        await boot();
        input().dispatchEvent(new Event('focus'));
        await flush();

        input().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(panel().classList.contains('d-none')).toBe(true);

        input().dispatchEvent(new Event('focus'));
        await flush();
        document.body.click();
        expect(panel().classList.contains('d-none')).toBe(true);
    });
});
