// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP
// server, no network. Exercises the REAL implementation in
// public/assets/js/news-scan-events.js (imported below, never
// reimplemented here) — the event search of /news/scan.
//
// window.ScoutMagicApi is stubbed rather than the global fetch: the
// production file is required to go through it, and a stub at that seam
// is what proves it does.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** @type {{getJson: any}} */
let api;

function buildPage() {
    document.body.innerHTML = `
        <input id="news-scan-event-search" type="search">
        <div id="news-scan-event-results"></div>
    `;
}

function stubApi(events) {
    api = {
        getJson: vi.fn().mockResolvedValue({ ok: true, status: 200, data: { success: true, events: events } }),
        escapeHtml: (v) => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'),
        debounce: (fn) => fn,
    };
    // @ts-ignore — the site's frontend toolbox.
    window.ScoutMagicApi = api;
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/news-scan-events.js');
}

function type(value) {
    const input = /** @type {HTMLInputElement} */ (document.getElementById('news-scan-event-search'));
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

function results() {
    return document.getElementById('news-scan-event-results').innerHTML;
}

beforeEach(() => {
    buildPage();
});

describe('the event search', () => {
    it('asks the JSON route and never a bare fetch', async () => {
        stubApi([]);
        await load();
        type('spaghetti');

        await vi.waitFor(() => expect(api.getJson).toHaveBeenCalled());
        expect(api.getJson.mock.calls[0][0]).toBe('/news/scan/events?q=spaghetti');
    });

    it('draws each event as a plain link, because choosing one navigates', async () => {
        // The event lives in the URL, not in a page state: the screen
        // stays open for two hours, a reload must not lose it, and the
        // address gets shared between two people taking turns.
        stubApi([
            { form_id: 7, title: 'Souper spaghetti', event_date: '2026-03-14', event_location: 'Salle paroissiale', seats: 120 },
        ]);
        await load();
        type('souper');

        await vi.waitFor(() => expect(results()).toContain('Souper spaghetti'));
        expect(results()).toContain('href="/news/scan/7"');
        expect(results()).toContain('14/03/2026');
        expect(results()).toContain('Salle paroissiale');
        expect(results()).toContain('120 places');
    });

    it('says so when an event carries no date', async () => {
        stubApi([{ form_id: 9, title: 'Barbecue', event_date: null, event_location: null, seats: 1 }]);
        await load();
        type('barbecue');

        await vi.waitFor(() => expect(results()).toContain('Barbecue'));
        expect(results()).toContain('date non renseignée');
        // One seat, one « place » — no stray plural.
        expect(results()).toContain('1 place<');
    });

    it('says nothing matched rather than leaving the list as it was', async () => {
        stubApi([]);
        await load();
        type('rien');

        await vi.waitFor(() => expect(results()).toContain('Aucun évènement ne correspond'));
    });

    it('escapes an event title', async () => {
        stubApi([{ form_id: 3, title: '<img src=x onerror=alert(1)>', event_date: null, event_location: null, seats: 2 }]);
        await load();
        type('x');

        await vi.waitFor(() => expect(results()).toContain('&lt;img'));
        expect(results()).not.toContain('<img src=x');
    });
});
