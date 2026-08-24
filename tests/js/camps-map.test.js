// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network, and no tiles: Leaflet is stubbed on
// `window.L` with the smallest surface the real file uses, so the map is
// never actually drawn and no request ever leaves. Exercises the REAL
// implementation in public/assets/js/camps-map.js (imported below, never
// reimplemented here).
//
// The fixture mirrors modules/camps/views/index.html.twig: a collapse
// panel holding the map container, its `data-places` island, and the card
// the markers write into.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PLACES = [
    { name: 'Domaine de Mozet', locality: '5340 Mozet', url: '/chefs/camps/lieux/1', lat: 50.42, lng: 4.98 },
    { name: 'Ferme du Moulin', locality: null, url: '/chefs/camps/lieux/2', lat: 50.28, lng: 5.91 },
];

function page(placesJson) {
    return `
        <button class="camps-map-toggle" type="button">Voir la carte</button>
        <div class="collapse" id="camps-map-panel">
            <div id="camps-map" data-places='${placesJson}'></div>
            <div class="camps-map-card"></div>
        </div>`;
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
 * Imports the real file and runs its DOMContentLoaded handler exactly
 * once.
 *
 * The handler is captured rather than dispatched: the file is an IIFE that
 * registers on `document`, `vi.resetModules()` re-runs it, and jsdom keeps
 * one document for the whole file — so a dispatched event would also fire
 * every previous test's still-registered handler and build the map twice.
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

function openPanel() {
    document.getElementById('camps-map-panel').dispatchEvent(new Event('shown.bs.collapse'));
}

describe('camps-map.js', () => {
    beforeEach(() => {
        delete window.L;
    });

    it('builds nothing until the panel is actually opened', async () => {
        // Building eagerly fetches tiles, which means contacting the tile
        // provider with the reader's IP for every chief who opens the
        // camps list and never looks at the map.
        const calls = await load();

        expect(calls.tileLayers).toHaveLength(0);
        expect(calls.markers).toHaveLength(0);
    });

    it('draws one marker per place once the panel opens', async () => {
        const calls = await load();

        openPanel();

        expect(calls.markers).toHaveLength(2);
        expect(calls.markers[0].position).toEqual([50.42, 4.98]);
        expect(calls.markers[1].position).toEqual([50.28, 5.91]);
    });

    it('fits the view to the places it has rather than staying on Wallonia', async () => {
        const calls = await load();

        openPanel();

        expect(calls.views[0]).toEqual([[50.45, 4.87], 8]);
        expect(calls.fitBounds).toHaveLength(1);
        expect(calls.fitBounds[0][1]).toEqual({ padding: [32, 32], maxZoom: 13 });
    });

    it('leaves the fallback view alone when there is nothing to fit', async () => {
        const calls = await load('[]');

        openPanel();

        expect(calls.markers).toHaveLength(0);
        expect(calls.fitBounds).toHaveLength(0);
        expect(calls.views[0]).toEqual([[50.45, 4.87], 8]);
    });

    it('builds once, however many times the panel is reopened', async () => {
        const calls = await load();

        openPanel();
        openPanel();
        openPanel();

        expect(calls.markers).toHaveLength(2);
        expect(calls.tileLayers).toHaveLength(1);
    });

    it('opens a card instead of navigating away when a marker is clicked', async () => {
        // On a phone, a mis-tap that leaves the map costs the whole
        // pan-and-zoom the reader just did.
        const calls = await load();
        openPanel();

        calls.markers[0].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.textContent).toContain('Domaine de Mozet');
        expect(card.textContent).toContain('5340 Mozet');
        expect(card.querySelector('a').getAttribute('href')).toBe('/chefs/camps/lieux/1');
    });

    it('replaces the card rather than stacking cards', async () => {
        const calls = await load();
        openPanel();

        calls.markers[0].fire('click');
        calls.markers[1].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.textContent).toContain('Ferme du Moulin');
        expect(card.textContent).not.toContain('Domaine de Mozet');
        expect(card.querySelectorAll('a')).toHaveLength(1);
    });

    it('omits the locality line for a place that has none', async () => {
        const calls = await load();
        openPanel();

        calls.markers[1].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.querySelectorAll('.text-body-secondary')).toHaveLength(0);
    });

    it('writes a place name as text, never as markup', async () => {
        const calls = await load(JSON.stringify([
            { name: '<img src=x onerror=alert(1)>', locality: null, url: '/chefs/camps/lieux/3', lat: 50, lng: 5 },
        ]));
        openPanel();

        calls.markers[0].fire('click');

        const card = document.querySelector('.camps-map-card');
        expect(card.querySelector('img')).toBeNull();
        expect(card.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('survives a data-places attribute that is not valid JSON', async () => {
        // A blank map beats a page whose script died halfway through.
        const calls = await load('not json at all');

        expect(() => openPanel()).not.toThrow();
        expect(calls.markers).toHaveLength(0);
    });

    it('does nothing at all when Leaflet failed to load', async () => {
        const ready = await importWith(page(JSON.stringify(PLACES)));
        ready();

        expect(() => openPanel()).not.toThrow();
        expect(document.querySelector('.camps-map-card').innerHTML).toBe('');
    });

    it('does nothing when the page carries no map', async () => {
        stubLeaflet();
        const ready = await importWith('<p>Aucun lieu géocodé.</p>');

        expect(() => ready()).not.toThrow();
    });
});
