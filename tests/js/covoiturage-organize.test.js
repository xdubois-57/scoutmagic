// Isolated JavaScript unit test — jsdom only, no network. Exercises the
// REAL public/assets/js/covoiturage-organize.js (imported below, never
// reimplemented): the carpool organiser's form — the place of the chosen
// events, and the point on the map.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm({ lat = '', lng = '', manual = '0', address = '', locate = false } = {}) {
    const locateUrl = locate ? 'data-locate-url="/covoiturage/organiser/adresse"' : '';
    document.body.innerHTML = `
        <div id="carpool-events"></div>
        <input id="carpool-address" value="${address}">
        <div class="d-none" id="carpool-location-warning">
            Lieux<span data-carpool-locations></span>
            <input type="checkbox" name="confirm_locations" value="1">
        </div>
        <fieldset data-carpool-point data-manual="${manual}" ${locateUrl}>
            <input type="hidden" name="point_automatic" value="0" data-carpool-point-automatic>
            <input type="hidden" name="point_manual" value="${manual}" data-carpool-point-manual>
            <input type="hidden" name="point_address" value="" data-carpool-point-address>
            <div data-carpool-point-fields>
                <input id="carpool-latitude" name="latitude" value="${lat}">
                <input id="carpool-longitude" name="longitude" value="${lng}">
            </div>
            <div class="d-none" data-carpool-point-map-box>
                <div data-carpool-point-map></div>
                <span data-carpool-point-line></span>
                <span data-carpool-point-origin></span>
                <button type="button" data-carpool-point-remove>Retirer</button>
            </div>
            <button type="button" class="d-none" data-carpool-point-place>Placer le point sur la carte</button>
        </fieldset>
    `;
}

function stubLeaflet() {
    const state = { markers: [], clickHandler: null, views: [], bounds: [], maps: 0 };
    const map = {
        setView: (center, zoom) => { state.views.push({ center, zoom }); return map; },
        fitBounds: (bounds) => { state.bounds.push(bounds); return map; },
        on: (event, handler) => { if (event === 'click') state.clickHandler = handler; return map; },
        invalidateSize: () => map,
    };
    window.L = {
        map: () => { state.maps++; return map; },
        tileLayer: () => ({ addTo: () => {} }),
        divIcon: (options) => ({ divIcon: options }),
        marker: (position, options = {}) => {
            let current = position;
            const handlers = {};
            const marker = {
                options,
                addTo: () => marker,
                on: (event, handler) => { handlers[event] = handler; return marker; },
                getLatLng: () => ({ lat: current[0], lng: current[1] }),
                setLatLng: (p) => { current = p; return marker; },
                remove: () => { marker.removed = true; return marker; },
                drag: (p) => { current = p; handlers.dragend(); },
            };
            state.markers.push(marker);
            return marker;
        },
    };
    return state;
}

/** The draggable pin, and the address's fixed marker. */
const pinOf = (leaflet) => leaflet.markers.filter((m) => m.options.draggable && !m.removed).at(-1);
const addressMarkerOf = (leaflet) => leaflet.markers.filter((m) => m.options.icon).at(-1);

function answer(data) {
    window.ScoutMagicApi.getJson = vi.fn().mockResolvedValue({ ok: true, status: 200, data });
}

async function settle() {
    await vi.waitFor(() => expect(window.ScoutMagicApi.getJson).toHaveBeenCalled());
    await Promise.resolve();
    await Promise.resolve();
}

function leave(address) {
    const field = /** @type {HTMLInputElement} */ (document.getElementById('carpool-address'));
    field.value = address;
    field.dispatchEvent(new Event('change'));
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/map.js');
    await import('../../public/assets/js/covoiturage-organize.js');
}

const value = (id) => /** @type {HTMLInputElement} */ (document.getElementById(id)).value;

describe('covoiturage-organize', () => {
    beforeEach(() => {
        delete window.L;
        delete window.ScoutMagicMap;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('the place of the chosen events', () => {
        async function choose(locations, ids = [1, 2]) {
            window.ScoutMagicApi.getJson = vi.fn().mockResolvedValue({ ok: true, status: 200, data: { success: true, locations } });
            document.getElementById('carpool-events').dispatchEvent(new CustomEvent('search-picker:change', {
                detail: { selected: ids.map((id) => ({ id, label: 'E' + id })) },
            }));
            await vi.waitFor(() => expect(window.ScoutMagicApi.getJson).toHaveBeenCalled());
            await Promise.resolve();
        }

        beforeEach(async () => {
            buildForm();
            await load();
        });

        it('asks for the places of the retained events and fills an empty address', async () => {
            await choose(['Plaine de Basse-Wavre']);

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledWith('/covoiturage/organiser/lieux?ids=1%2C2');
            expect(value('carpool-address')).toBe('Plaine de Basse-Wavre');
            expect(document.getElementById('carpool-location-warning').classList.contains('d-none')).toBe(true);
        });

        it('never overwrites an address the chief typed', async () => {
            /** @type {HTMLInputElement} */ (document.getElementById('carpool-address')).value = 'Entrée nord';
            await choose(['Plaine de Basse-Wavre']);

            expect(value('carpool-address')).toBe('Entrée nord');
        });

        it('warns when the events disagree about the place', async () => {
            await choose(['Plaine de Basse-Wavre', 'Gîte de Han']);

            const warning = document.getElementById('carpool-location-warning');
            expect(warning.classList.contains('d-none')).toBe(false);
            expect(warning.textContent).toContain('Plaine de Basse-Wavre ; Gîte de Han');
        });
    });

    describe('the point', () => {
        it('leaves the two coordinate fields alone when Leaflet is not there', async () => {
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            expect(document.querySelector('[data-carpool-point-fields]').classList.contains('d-none')).toBe(false);
        });

        it('shows a draggable pin, and a drag makes it a hand-placed point', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.718400', lng: '4.635900' });
            await load();

            expect(document.querySelector('[data-carpool-point-fields]').classList.contains('d-none')).toBe(true);
            expect(document.querySelector('[data-carpool-point-origin]').textContent).toBe('trouvé depuis l\'adresse');

            leaflet.markers[0].drag([50.7201, 4.6412]);

            expect(value('carpool-latitude')).toBe('50.720100');
            expect(value('carpool-longitude')).toBe('4.641200');
            expect(document.querySelector('[data-carpool-point-origin]').textContent).toBe('point placé à la main');
        });

        it('places a pin with a click when there was none', async () => {
            const leaflet = stubLeaflet();
            buildForm();
            await load();

            /** @type {HTMLButtonElement} */ (document.querySelector('[data-carpool-point-place]')).click();
            leaflet.clickHandler({ latlng: { lat: 50.1, lng: 4.2 } });

            expect(value('carpool-latitude')).toBe('50.100000');
            expect(value('carpool-longitude')).toBe('4.200000');
        });

        it('removes the point and empties the fields the form posts', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            /** @type {HTMLButtonElement} */ (document.querySelector('[data-carpool-point-remove]')).click();

            expect(leaflet.markers[0].removed).toBe(true);
            expect(value('carpool-latitude')).toBe('');
            expect(value('carpool-longitude')).toBe('');
            expect(document.querySelector('[data-carpool-point-place]').classList.contains('d-none')).toBe(false);
        });
    });

    describe('the address on the map (#642)', () => {
        const FOUND = { success: true, found: true, latitude: 50.125, longitude: 5.187 };

        it('centres on the address when the field is left, marks it, and puts an untouched pin there', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: true });
            await load();
            answer(FOUND);

            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledWith(
                '/covoiturage/organiser/adresse?q=G%C3%AEte%20de%20Han%2C%20rue%20des%20Grottes%2012'
            );
            expect(document.querySelector('[data-carpool-point-map-box]').classList.contains('d-none')).toBe(false);
            expect(document.querySelector('[data-carpool-point-place]').classList.contains('d-none')).toBe(true);
            expect(addressMarkerOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
            expect(addressMarkerOf(leaflet).options.interactive).toBe(false);
            expect(pinOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
            expect(leaflet.views.at(-1)).toEqual({ center: [50.125, 5.187], zoom: 15 });
            expect(value('carpool-latitude')).toBe('50.125000');
            expect(value('carpool-longitude')).toBe('5.187000');
            expect(document.querySelector('[data-carpool-point-automatic]').value).toBe('1');
            expect(document.querySelector('[data-carpool-point-address]').value).toBe('Gîte de Han, rue des Grottes 12');
            expect(document.querySelector('[data-carpool-point-origin]').textContent).toBe('trouvé depuis l\'adresse');
        });

        it('tells the server which address an untouched pin was found for, even before a new lookup answers', async () => {
            // Lookups that never answer: the form is sent while they run.
            vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
            try {
                stubLeaflet();
                buildForm({ lat: '50.7', lng: '4.6', address: 'Plaine de Basse-Wavre', locate: true });
                await load();
                expect(document.querySelector('[data-carpool-point-address]').value).toBe('Plaine de Basse-Wavre');

                leave('Gîte de Han, rue des Grottes 12');
                expect(value('carpool-latitude')).toBe('50.700000');
                expect(document.querySelector('[data-carpool-point-automatic]').value).toBe('1');
                expect(document.querySelector('[data-carpool-point-address]').value).toBe('Plaine de Basse-Wavre');
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('keeps a pin moved by hand where it is, and moves the address marker', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: true });
            await load();
            answer(FOUND);
            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            pinOf(leaflet).drag([50.2, 5.2]);
            expect(document.querySelector('[data-carpool-point-automatic]').value).toBe('0');
            expect(document.querySelector('[data-carpool-point-address]').value).toBe('');

            answer({ success: true, found: true, latitude: 50.4, longitude: 4.9 });
            leave('Place du Marché 1, Namur');
            await settle();

            expect(pinOf(leaflet).getLatLng()).toEqual({ lat: 50.2, lng: 5.2 });
            expect(addressMarkerOf(leaflet).getLatLng()).toEqual({ lat: 50.4, lng: 4.9 });
            expect(leaflet.bounds.at(-1)).toEqual([[50.2, 5.2], [50.4, 4.9]]);
            expect(value('carpool-latitude')).toBe('50.200000');
            expect(document.querySelector('[data-carpool-point-automatic]').value).toBe('0');
        });

        it('drops the address marker when a new address is not found', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: true });
            await load();
            answer(FOUND);
            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            answer({ success: true, found: false });
            leave('Le pré de Jules');
            await settle();

            expect(addressMarkerOf(leaflet).removed).toBe(true);
        });

        it('drops an untouched pin found for an old address when the new one is not found', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: true });
            await load();
            answer(FOUND);
            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            // Not found, over quota or busy: all the same « found: false ».
            answer({ success: true, found: false });
            leave('Place du Marché 1, Namur');
            await settle();

            expect(pinOf(leaflet)).toBeUndefined();
            expect(value('carpool-latitude')).toBe('');
            expect(document.querySelector('[data-carpool-point-automatic]').value).toBe('1');
            expect(document.querySelector('[data-carpool-point-place]').classList.contains('d-none')).toBe(false);
        });

        it('drops a saved automatic pin when the address changes and the lookup is switched off', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: false, address: 'Gîte de Han', lat: '50.125000', lng: '5.187000' });
            await load();
            window.ScoutMagicApi.getJson = vi.fn();

            leave('Place du Marché 1, Namur');

            expect(window.ScoutMagicApi.getJson).not.toHaveBeenCalled();
            expect(pinOf(leaflet)).toBeUndefined();
            expect(value('carpool-latitude')).toBe('');
        });

        it('keeps the saved automatic pin of the saved address when its lookup is refused on opening', async () => {
            const fetch = vi.fn().mockResolvedValue({
                ok: true, status: 200, json: () => Promise.resolve({ success: true, found: false }),
            });
            vi.stubGlobal('fetch', fetch);
            try {
                const leaflet = stubLeaflet();
                buildForm({ locate: true, address: 'Gîte de Han', lat: '50.125000', lng: '5.187000' });
                await load();
                await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
                await Promise.resolve();
                await Promise.resolve();

                expect(pinOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
                expect(value('carpool-latitude')).toBe('50.125000');
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('looks the address up when the events fill it, and when the form opens with one', async () => {
            stubLeaflet();
            buildForm({ locate: true });
            await load();
            window.ScoutMagicApi.getJson = vi.fn()
                .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true, locations: ['Plaine de Basse-Wavre'] } })
                .mockResolvedValue({ ok: true, status: 200, data: FOUND });
            document.getElementById('carpool-events').dispatchEvent(new CustomEvent('search-picker:change', {
                detail: { selected: [{ id: 1, label: 'E1' }] },
            }));
            await vi.waitFor(() => expect(window.ScoutMagicApi.getJson).toHaveBeenCalledTimes(2));
            expect(window.ScoutMagicApi.getJson).toHaveBeenLastCalledWith(
                '/covoiturage/organiser/adresse?q=Plaine%20de%20Basse-Wavre'
            );

            // On load, the lookup starts before a test could replace
            // ScoutMagicApi.getJson (load() re-imports api.js): the network
            // underneath it is what is stubbed instead.
            const fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve(FOUND) });
            vi.stubGlobal('fetch', fetch);
            try {
                stubLeaflet();
                buildForm({ locate: true, address: 'Gîte de Han' });
                await load();
                await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
                expect(fetch.mock.calls[0][0]).toBe('/covoiturage/organiser/adresse?q=G%C3%AEte%20de%20Han');
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('never asks when the lookup is switched off, nor for a line too short to be a place', async () => {
            stubLeaflet();
            buildForm({ locate: false });
            await load();
            window.ScoutMagicApi.getJson = vi.fn();
            leave('Gîte de Han, rue des Grottes 12');

            buildForm({ locate: true });
            await load();
            window.ScoutMagicApi.getJson = vi.fn();
            leave('ab');

            await Promise.resolve();
            expect(window.ScoutMagicApi.getJson).not.toHaveBeenCalled();
        });

        it('leaves a point the chief removed removed, and opens « Placer » on the address', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.72', lng: '4.64', locate: true });
            await load();
            /** @type {HTMLButtonElement} */ (document.querySelector('[data-carpool-point-remove]')).click();

            answer(FOUND);
            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            expect(document.querySelector('[data-carpool-point-map-box]').classList.contains('d-none')).toBe(true);
            expect(value('carpool-latitude')).toBe('');
            expect(document.querySelector('[data-carpool-point-manual]').value).toBe('1');

            /** @type {HTMLButtonElement} */ (document.querySelector('[data-carpool-point-place]')).click();
            expect(leaflet.views.at(-1)).toEqual({ center: [50.125, 5.187], zoom: 15 });
            expect(addressMarkerOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
        });
    });
});
