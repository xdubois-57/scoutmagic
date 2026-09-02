// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network, and no tiles: Leaflet is stubbed on
// `window.L` with the smallest surface the real file uses, so the map is
// never actually drawn and no request ever leaves. Exercises the REAL
// implementation in public/assets/js/camps-map.js (imported below, never
// reimplemented here).
//
// The fixture mirrors modules/camps/views/list.html.twig: the « Carte »
// toggle, the collapse panel it controls — rendered OPEN (`collapse show`)
// by the server — the map container with its `data-places` island, and
// the card the markers write into.
//
// Two things are under test here that a browser alone would tell us
// slowly: the map is built on load rather than on a click, and the
// reader's fold is remembered ONLY with functional cookie consent
// (AGENTS.md § Cookie consent, key `camps_map_collapsed` declared in
// modules/camps/module.json's `cookies` section — that the declaration
// matches the key written here is checked by
// Tests\Modules\Camps\Service\MapTilesTest).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const KEY = 'camps_map_collapsed';

const PLACES = [
    { name: 'Domaine de Mozet', locality: '5340 Mozet', url: '/chefs/camps/lieux/1', lat: 50.42, lng: 4.98 },
    { name: 'Ferme du Moulin', locality: null, url: '/chefs/camps/lieux/2', lat: 50.28, lng: 5.91 },
];

function page(placesJson) {
    return `
        <button class="btn btn-outline-secondary camps-map-toggle" type="button"
                data-bs-toggle="collapse" data-bs-target="#camps-map-panel"
                aria-expanded="true" aria-controls="camps-map-panel">Carte</button>
        <div class="collapse show" id="camps-map-panel">
            <div id="camps-map" data-places='${placesJson}'></div>
            <div class="camps-map-card"></div>
        </div>`;
}

/**
 * jsdom implements no matchMedia, and the map's default now depends on
 * one. Absent, the script treats the screen as wide — showing a map to
 * somebody who did not need it being a smaller mistake than hiding it
 * from somebody who did — so a test about a phone has to say so.
 */
function stubViewport(desktop) {
    window.matchMedia = /** @type {typeof window.matchMedia} */ (
        /** @type {unknown} */ ((query) => ({ matches: desktop, media: query }))
    );
}

function clearConsentCookie() {
    document.cookie = 'cookie_consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
}

function giveConsent(functional) {
    document.cookie = 'cookie_consent=' + encodeURIComponent(JSON.stringify({ functional, analytics: false }));
}

/**
 * The slice of Leaflet camps-map.js touches. Every call is recorded so a
 * test can ask what the file asked the map to do without a canvas, a tile
 * request or a layout.
 */
function stubLeaflet() {
    const calls = { views: [], tileLayers: [], markers: [], fitBounds: [] };

    const map = {
        setView(center, zoom) {
            calls.views.push([center, zoom]);
            return map;
        },
        fitBounds(bounds, options) {
            calls.fitBounds.push([bounds, options]);
        },
    };

    window.L = {
        map: () => map,
        tileLayer: (url, options) => ({
            addTo() {
                calls.tileLayers.push([url, options]);
            },
        }),
        marker: (position) => {
            const handlers = {};
            const marker = {
                position,
                addTo: () => marker,
                on: (event, handler) => {
                    handlers[event] = handler;
                },
                fire: (event) => handlers[event] && handlers[event](),
            };
            calls.markers.push(marker);
            return marker;
        },
        featureGroup: (markers) => ({
            getBounds: () => ({ markers }),
        }),
    };

    return calls;
}

/**
 * Imports the real file and returns its DOMContentLoaded handler, without
 * running it.
 *
 * The handler is captured rather than dispatched: the file is an IIFE that
 * registers on `document`, `vi.resetModules()` re-runs it, and jsdom keeps
 * one document for the whole file — so a dispatched event would also fire
 * every previous test's still-registered handler and build the map twice.
 *
 * The import itself is not inert: the file applies a stored fold as soon
 * as it runs, which is what keeps a folded map from flashing open behind
 * Leaflet's own script. Returning the handler unrun is what lets a test
 * observe that.
 *
 * @param {string} markup
 * @returns {Promise<() => void>} the captured handler
 */
async function importWith(markup) {
    vi.resetModules();
    document.body.innerHTML = markup;

    let ready = () => {};
    const register = document.addEventListener.bind(document);
    const spy = vi.spyOn(document, 'addEventListener').mockImplementation((event, handler) => {
        if (event === 'DOMContentLoaded') {
            ready = /** @type {() => void} */ (handler);

            return;
        }
        register(event, handler);
    });

    await import('../../public/assets/js/camps-map.js');
    spy.mockRestore();

    return ready;
}

async function load(placesJson = JSON.stringify(PLACES)) {
    const calls = stubLeaflet();
    const ready = await importWith(page(placesJson.replace(/'/g, '&#39;')));
    ready();

    return calls;
}

function panel() {
    return document.getElementById('camps-map-panel');
}

function toggle() {
    return document.querySelector('.camps-map-toggle');
}

/** Bootstrap's own event, once the panel has finished opening. */
function openPanel() {
    panel().classList.add('show');
    panel().dispatchEvent(new Event('shown.bs.collapse'));
}

/** Bootstrap's own event, once the panel has finished closing. */
function closePanel() {
    panel().classList.remove('show');
    panel().dispatchEvent(new Event('hidden.bs.collapse'));
}

describe('camps-map.js', () => {
    beforeEach(() => {
        delete window.L;
        localStorage.clear();
        clearConsentCookie();
        // @ts-expect-error — removing the stub a previous test installed.
        delete window.matchMedia;
    });

    it('builds the map on load, with no click anywhere', async () => {
        // Expanded by default (modules/camps/views/list.html.twig): the
        // question this screen answers is "où est-on déjà allés", and a
        // map nobody unfolds answers it for nobody. The cost — the tile
        // provider seeing the reader's IP from the first visit — is
        // stated in core/View/rgpd_default.html rather than avoided.
        const calls = await load();

        expect(calls.tileLayers).toHaveLength(1);
        expect(calls.markers).toHaveLength(2);
        expect(calls.markers[0].position).toEqual([50.42, 4.98]);
        expect(calls.markers[1].position).toEqual([50.28, 5.91]);
    });

    it('fits the view to the places it has rather than staying on Wallonia', async () => {
        const calls = await load();

        expect(calls.views[0]).toEqual([[50.45, 4.87], 8]);
        expect(calls.fitBounds).toHaveLength(1);
        expect(calls.fitBounds[0][1]).toEqual({ padding: [32, 32], maxZoom: 13 });
    });

    it('leaves the fallback view alone when there is nothing to fit', async () => {
        const calls = await load('[]');

        expect(calls.markers).toHaveLength(0);
        expect(calls.fitBounds).toHaveLength(0);
        expect(calls.views[0]).toEqual([[50.45, 4.87], 8]);
    });

    it('builds once, however many times the panel is folded and reopened', async () => {
        const calls = await load();

        closePanel();
        openPanel();
        closePanel();
        openPanel();

        expect(calls.markers).toHaveLength(2);
        expect(calls.tileLayers).toHaveLength(1);
    });

    it('opens a card instead of navigating away when a marker is clicked', async () => {
        // On a phone, a mis-tap that leaves the map costs the whole
        // pan-and-zoom the reader just did.
        const calls = await load();

        calls.markers[0].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.textContent).toContain('Domaine de Mozet');
        expect(card.textContent).toContain('5340 Mozet');
        expect(card.querySelector('a').getAttribute('href')).toBe('/chefs/camps/lieux/1');
    });

    it('replaces the card rather than stacking cards', async () => {
        const calls = await load();

        calls.markers[0].fire('click');
        calls.markers[1].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.textContent).toContain('Ferme du Moulin');
        expect(card.textContent).not.toContain('Domaine de Mozet');
        expect(card.querySelectorAll('a')).toHaveLength(1);
    });

    it('omits the locality line for a place that has none', async () => {
        const calls = await load();

        calls.markers[1].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.querySelectorAll('.text-body-secondary')).toHaveLength(0);
    });

    it('writes a place name as text, never as markup', async () => {
        const calls = await load(JSON.stringify([
            { name: '<img src=x onerror=alert(1)>', locality: null, url: '/chefs/camps/lieux/3', lat: 50, lng: 5 },
        ]));

        calls.markers[0].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.querySelector('img')).toBeNull();
        expect(card.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('survives a data-places attribute that is not valid JSON', async () => {
        // A blank map beats a page whose script died halfway through.
        const calls = await load('not json at all');

        expect(calls.tileLayers).toHaveLength(1);
        expect(calls.markers).toHaveLength(0);
    });

    it('does nothing at all when Leaflet failed to load', async () => {
        const ready = await importWith(page(JSON.stringify(PLACES)));

        expect(() => ready()).not.toThrow();
        expect(() => openPanel()).not.toThrow();
        expect(document.querySelector('.camps-map-card').innerHTML).toBe('');
    });

    it('does nothing when the page carries no map', async () => {
        stubLeaflet();
        const ready = await importWith('<p>Aucun lieu géocodé.</p>');

        expect(() => ready()).not.toThrow();
    });

    describe('remembering the fold (functional consent)', () => {
        it('stores the fold once functional consent is given', async () => {
            giveConsent(true);
            await load();

            closePanel();

            expect(localStorage.getItem(KEY)).toBe('1');
        });

        it('stores unfolded too, because absence now means « ask the screen »', async () => {
            // It used to be stored as an absence, absence meaning « the
            // default, which is expanded ». With a default that depends on
            // the screen, a reader who unfolds the map on their phone would
            // find it folded again on every visit — their choice silently
            // unstorable.
            giveConsent(true);
            localStorage.setItem(KEY, '1');
            await load();

            openPanel();

            expect(localStorage.getItem(KEY)).toBe('0');
        });

        it('never writes anything without a consent cookie', async () => {
            // AGENTS.md § Cookie consent: no non-essential storage key is
            // ever written before consent covers its category.
            await load();

            closePanel();

            expect(localStorage.getItem(KEY)).toBeNull();
        });

        it('never writes anything when functional consent was refused', async () => {
            giveConsent(false);
            await load();

            closePanel();

            expect(localStorage.getItem(KEY)).toBeNull();
        });

        it('reopens folded, and leaves the tiles unrequested, when that is what was stored', async () => {
            giveConsent(true);
            localStorage.setItem(KEY, '1');
            const calls = await load();

            expect(panel().classList.contains('show')).toBe(false);
            expect(calls.tileLayers).toHaveLength(0);
            expect(calls.markers).toHaveLength(0);
        });

        it('tells the toggle what it is announcing when it folds the panel itself', async () => {
            // Bootstrap has no Collapse instance yet — it builds one on
            // the first click — so the class and aria-expanded are ours
            // to keep in step with what is on screen.
            giveConsent(true);
            localStorage.setItem(KEY, '1');
            await load();

            expect(toggle().getAttribute('aria-expanded')).toBe('false');
            expect(toggle().classList.contains('collapsed')).toBe(true);
        });

        it('honours a stored unfold on a phone, over the narrow-screen default', async () => {
            stubViewport(false);
            giveConsent(true);
            localStorage.setItem(KEY, '0');
            const calls = await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(calls.tileLayers).toHaveLength(1);
        });
    });

    describe('the default depends on the screen', () => {
        it('opens on a wide screen, where a map answers the question the page asks', async () => {
            stubViewport(true);
            const calls = await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(calls.tileLayers).toHaveLength(1);
        });

        it('stays folded on a phone, and requests no tile at all', async () => {
            // A full-width map that captures touch sits between the reader
            // and the list they came for, and every attempt to scroll past
            // it pans the map instead.
            stubViewport(false);
            const calls = await load();

            expect(panel().classList.contains('show')).toBe(false);
            expect(calls.tileLayers).toHaveLength(0);
            expect(calls.markers).toHaveLength(0);
        });

        it('tells the toggle it is folded on a phone', async () => {
            stubViewport(false);
            await load();

            expect(toggle().getAttribute('aria-expanded')).toBe('false');
            expect(toggle().classList.contains('collapsed')).toBe(true);
        });

        it('folds on a phone even with no consent, since nothing needs storing to do it', async () => {
            // The narrow-screen default is not a preference and is not
            // remembered: it is what the screen is.
            stubViewport(false);
            giveConsent(false);
            await load();

            expect(panel().classList.contains('show')).toBe(false);
            expect(localStorage.getItem(KEY)).toBeNull();
        });

        it('treats a browser with no matchMedia as a wide screen', async () => {
            // Showing a map to somebody who did not need it is a smaller
            // mistake than hiding it from somebody who did.
            const calls = await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(calls.tileLayers).toHaveLength(1);
        });

        it('treats a matchMedia that throws as a wide screen too', async () => {
            window.matchMedia = /** @type {typeof window.matchMedia} */ (
                /** @type {unknown} */ (() => {
                    throw new Error('nope');
                })
            );

            const calls = await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(calls.tileLayers).toHaveLength(1);
        });
    });

    describe('remembering the fold, continued', () => {
        it('folds the panel as the file runs, before its DOMContentLoaded handler', async () => {
            // The <script> sits at the end of <body>, so applying the
            // fold here rather than at DOMContentLoaded is what keeps a
            // folded map from flashing open on every visit.
            giveConsent(true);
            localStorage.setItem(KEY, '1');
            stubLeaflet();

            await importWith(page(JSON.stringify(PLACES).replace(/'/g, '&#39;')));

            expect(panel().classList.contains('show')).toBe(false);
        });

        it('leaves the toggle alone when nothing was stored', async () => {
            giveConsent(true);
            await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(toggle().getAttribute('aria-expanded')).toBe('true');
            expect(toggle().classList.contains('collapsed')).toBe(false);
        });

        it('ignores a stored fold without consent, and drops what it may not read', async () => {
            // Consent granted, map folded, consent withdrawn: nothing
            // else on this page would ever come back to remove the key,
            // so the read that may not use it is what clears it.
            localStorage.setItem(KEY, '1');
            const calls = await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(calls.tileLayers).toHaveLength(1);
            expect(localStorage.getItem(KEY)).toBeNull();
        });

        it('ignores a stored fold when functional consent was refused', async () => {
            giveConsent(false);
            localStorage.setItem(KEY, '1');
            await load();

            expect(panel().classList.contains('show')).toBe(true);
            expect(localStorage.getItem(KEY)).toBeNull();
        });
    });
});
