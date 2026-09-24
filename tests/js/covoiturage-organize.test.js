// Isolated JavaScript unit test — jsdom only, no network. Exercises the
// REAL public/assets/js/covoiturage-organize.js (imported below, never
// reimplemented): the carpool organiser's form — the place of the chosen
// events, and the point on the map.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm({ lat = '', lng = '', manual = '0' } = {}) {
    document.body.innerHTML = `
        <div id="carpool-events"></div>
        <input id="carpool-address" value="">
        <div class="d-none" id="carpool-location-warning">
            Lieux<span data-carpool-locations></span>
            <input type="checkbox" name="confirm_locations" value="1">
        </div>
        <fieldset data-carpool-point data-manual="${manual}">
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
    const state = { markers: [], clickHandler: null };
    const map = {
        setView: () => map,
        on: (event, handler) => { if (event === 'click') state.clickHandler = handler; return map; },
        invalidateSize: () => map,
    };
    window.L = {
        map: () => map,
        tileLayer: () => ({ addTo: () => {} }),
        marker: (position) => {
            let current = position;
            const handlers = {};
            const marker = {
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
});
