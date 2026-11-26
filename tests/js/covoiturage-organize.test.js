// Isolated JavaScript unit test — jsdom only, no network. Exercises the
// REAL public/assets/js/covoiturage-organize.js (imported below, never
// reimplemented): the carpool organiser's form — the place of the chosen
// events, and how it hands the address to the core's point picker. The
// picker itself is tested on its own (tests/js/point-picker.test.js).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm({ lat = '', lng = '', manual = '0', address = '', locate = false, pointAddress = '', outbound = '', back = '' } = {}) {
    const locateUrl = locate ? 'data-locate-url="/covoiturage/organiser/adresse"' : '';
    document.body.innerHTML = `
        <div id="carpool-events"></div>
        <input id="carpool-address" value="${address}">
        <p class="d-none" id="carpool-address-status"></p>
        <input type="date" id="carpool-outbound" value="${outbound}">
        <input type="date" id="carpool-return" value="${back}">
        <div class="d-none" id="carpool-location-warning">
            Lieux<span data-carpool-locations></span>
            <input type="checkbox" name="confirm_locations" value="1">
        </div>
        <fieldset data-point-picker data-manual="${manual}" ${locateUrl}
                  data-address-field="carpool-address" data-address-status="carpool-address-status">
            <input type="hidden" name="point_automatic" value="0" data-point-picker-automatic>
            <input type="hidden" name="point_manual" value="${manual}" data-point-picker-manual>
            <input type="hidden" name="point_address" value="${pointAddress}" data-point-picker-address>
            <div data-point-picker-fields>
                <input id="carpool-latitude" name="latitude" value="${lat}" data-point-picker-latitude>
                <input id="carpool-longitude" name="longitude" value="${lng}" data-point-picker-longitude>
            </div>
            <div class="d-none" data-point-picker-map-box>
                <div data-point-picker-map></div>
                <span data-point-picker-line></span>
                <span data-point-picker-origin></span>
                <button type="button" data-point-picker-remove>Retirer</button>
            </div>
            <button type="button" class="d-none" data-point-picker-place>Placer le point sur la carte</button>
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

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/map.js');
    await import('../../public/assets/js/point-picker.js');
    await import('../../public/assets/js/covoiturage-organize.js');
}

const value = (id) => /** @type {HTMLInputElement} */ (document.getElementById(id)).value;

describe('covoiturage-organize', () => {
    beforeEach(() => {
        delete window.L;
        delete window.ScoutMagicMap;
        delete window.ScoutMagicPointPicker;
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

        // Issue #692: the events' dates fill the date fields still empty —
        // the earliest start and the latest end — never over a typed one.
        async function chooseWithDates(dates) {
            window.ScoutMagicApi.getJson = vi.fn().mockResolvedValue({
                ok: true, status: 200, data: { success: true, locations: [], dates },
            });
            document.getElementById('carpool-events').dispatchEvent(new CustomEvent('search-picker:change', {
                detail: { selected: [{ id: 1, label: 'E1' }, { id: 2, label: 'E2' }] },
            }));
            await vi.waitFor(() => expect(window.ScoutMagicApi.getJson).toHaveBeenCalled());
            await Promise.resolve();
        }

        it('fills the empty dates with the events\' earliest start and latest end', async () => {
            await chooseWithDates({ outbound: '2026-10-10', return: '2026-10-12' });

            expect(value('carpool-outbound')).toBe('2026-10-10');
            expect(value('carpool-return')).toBe('2026-10-12');
        });

        it('never overwrites a date the chief typed', async () => {
            buildForm({ outbound: '2026-10-09' });
            await load();

            await chooseWithDates({ outbound: '2026-10-10', return: '2026-10-12' });

            expect(value('carpool-outbound')).toBe('2026-10-09');
            expect(value('carpool-return')).toBe('2026-10-12');
        });

        it('leaves the return empty for a one-day outing', async () => {
            await chooseWithDates({ outbound: '2026-10-10', return: null });

            expect(value('carpool-outbound')).toBe('2026-10-10');
            expect(value('carpool-return')).toBe('');
        });

        it('ignores an answer without dates, or with a date that is not one', async () => {
            await chooseWithDates(null);
            expect(value('carpool-outbound')).toBe('');

            await chooseWithDates({ outbound: '10/10/2026', return: '' });
            expect(value('carpool-outbound')).toBe('');
            expect(value('carpool-return')).toBe('');
        });

        it('warns when the events disagree about the place', async () => {
            await choose(['Plaine de Basse-Wavre', 'Gîte de Han']);

            const warning = document.getElementById('carpool-location-warning');
            expect(warning.classList.contains('d-none')).toBe(false);
            expect(warning.textContent).toContain('Plaine de Basse-Wavre ; Gîte de Han');
        });
    });

    describe('the point on the map (the core\'s point picker, #1353)', () => {
        const FOUND = { success: true, found: true, latitude: 50.125, longitude: 5.187 };

        it('leaves the two coordinate fields alone when Leaflet is not there', async () => {
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            expect(document.querySelector('[data-point-picker-fields]').classList.contains('d-none')).toBe(false);
        });

        it('turns the carpool\'s fields into the core\'s map', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.718400', lng: '4.635900' });
            await load();

            expect(document.querySelector('[data-point-picker-fields]').classList.contains('d-none')).toBe(true);
            leaflet.markers[0].drag([50.7201, 4.6412]);
            expect(value('carpool-latitude')).toBe('50.720100');
            expect(document.querySelector('[data-point-picker-origin]').textContent).toBe('point placé à la main');
        });

        it('looks the address up through the carpool\'s route when the events fill it', async () => {
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
        });

        it('fills the address from the events without a map, and asks for nothing more', async () => {
            buildForm({ locate: true });
            await load();
            window.ScoutMagicApi.getJson = vi.fn()
                .mockResolvedValue({ ok: true, status: 200, data: { success: true, locations: ['Plaine de Basse-Wavre'] } });
            document.getElementById('carpool-events').dispatchEvent(new CustomEvent('search-picker:change', {
                detail: { selected: [{ id: 1, label: 'E1' }] },
            }));
            await vi.waitFor(() => expect(value('carpool-address')).toBe('Plaine de Basse-Wavre'));
            await Promise.resolve();

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledTimes(1);
        });
    });
});
