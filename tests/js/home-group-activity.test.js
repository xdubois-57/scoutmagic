// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: `fetch` is a stub. Exercises the REAL
// implementation in public/assets/js/home-group-activity.js (imported
// below, never reimplemented here). That file is an IIFE that registers its
// listener at import time, so each test builds its fixture first and
// imports the module through vi.resetModules() + await import().
//
// The fixture mirrors core/View/templates/pages/home.html.twig: the one band
// above the welcome text, which carries the group activity banner when
// there is something new in the visitor's groups (issue #704).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const BANNER = `
    <div class="alert alert-info" data-home-group-activity>
        <strong>Du nouveau dans vos groupes</strong>
        <a href="/groups/7">Ouvrir Les Loups</a>
    </div>`;

/** @param {string} bandHtml */
function page(bandHtml) {
    return `<div data-home-band>${bandHtml}</div><h1>Bienvenue</h1>`;
}

/** @param {string} html */
function respondingWith(html, ok = true) {
    return vi.fn(() => Promise.resolve({ ok, text: () => Promise.resolve(html) }));
}

async function load(bandHtml) {
    vi.resetModules();
    document.body.innerHTML = page(bandHtml);
    await import('../../public/assets/js/home-group-activity.js');
}

/** @param {boolean} persisted */
function showPage(persisted) {
    const event = new Event('pageshow');
    Object.defineProperty(event, 'persisted', { value: persisted });
    window.dispatchEvent(event);
}

/** Lets the stubbed fetch and its two `then`s run. */
async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function band() {
    return document.querySelector('[data-home-band]');
}

describe('home-group-activity.js', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('takes the band away when the page comes back from the cache and nothing is new any more', async () => {
        // The visitor opened the group from the band and pressed back: the
        // browser restores the old page, band included.
        await load(BANNER);
        const fetchStub = respondingWith(page(''));
        vi.stubGlobal('fetch', fetchStub);

        showPage(true);
        await settle();

        expect(fetchStub).toHaveBeenCalledTimes(1);
        expect(band().querySelector('[data-home-group-activity]')).toBeNull();
    });

    it('shows what the server says now rather than what the page said when it was left', async () => {
        await load(BANNER);
        vi.stubGlobal('fetch', respondingWith(page(
            '<div class="alert alert-info" data-home-group-activity>Ouvrir Les Castors</div>'
        )));

        showPage(true);
        await settle();

        expect(band().textContent).toContain('Ouvrir Les Castors');
        expect(band().textContent).not.toContain('Ouvrir Les Loups');
    });

    it('asks for the page without the cache and with the visitor\'s own session', async () => {
        await load(BANNER);
        const fetchStub = respondingWith(page(''));
        vi.stubGlobal('fetch', fetchStub);

        showPage(true);
        await settle();

        const init = fetchStub.mock.calls[0][1];
        expect(init.cache).toBe('no-store');
        expect(init.credentials).toBe('same-origin');
    });

    it('leaves an ordinary load alone', async () => {
        await load(BANNER);
        const fetchStub = respondingWith(page(''));
        vi.stubGlobal('fetch', fetchStub);

        showPage(false);
        await settle();

        expect(fetchStub).not.toHaveBeenCalled();
        expect(band().querySelector('[data-home-group-activity]')).not.toBeNull();
    });

    it('asks nothing of a page that shows no band', async () => {
        await load('');
        const fetchStub = respondingWith(page(BANNER));
        vi.stubGlobal('fetch', fetchStub);

        showPage(true);
        await settle();

        expect(fetchStub).not.toHaveBeenCalled();
    });

    it('keeps the band when the server cannot be reached', async () => {
        await load(BANNER);
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('offline'))));

        showPage(true);
        await settle();

        expect(band().querySelector('[data-home-group-activity]')).not.toBeNull();
    });

    it('keeps the band when the server answers with an error', async () => {
        await load(BANNER);
        vi.stubGlobal('fetch', respondingWith('<p>Erreur</p>', false));

        showPage(true);
        await settle();

        expect(band().querySelector('[data-home-group-activity]')).not.toBeNull();
    });

    it('keeps the band when the answer is not the home page', async () => {
        // A session that expired meanwhile lands on some other page: no
        // band to read there, so nothing is touched.
        await load(BANNER);
        vi.stubGlobal('fetch', respondingWith('<h1>Connexion</h1>'));

        showPage(true);
        await settle();

        expect(band().querySelector('[data-home-group-activity]')).not.toBeNull();
    });
});
