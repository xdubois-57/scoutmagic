// Isolated JavaScript unit test — jsdom only, no network. Exercises the
// REAL public/assets/js/point-picker.js (imported below, never
// reimplemented): the core's point picker (#1353), on markup that names no
// module — the carpool and the camps forms only configure it.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm({ lat = '', lng = '', manual = '0', address = '', locate = false, pointAddress = '', labels = '' } = {}) {
    const locateUrl = locate ? 'data-locate-url="/lookup/address"' : '';
    document.body.innerHTML = `
        <input id="place-address" value="${address}">
        <p class="d-none" id="place-address-status"></p>
        <fieldset data-point-picker data-manual="${manual}" ${locateUrl}
                  data-address-field="place-address" data-address-status="place-address-status" ${labels}>
            <input type="hidden" name="point_automatic" value="0" data-point-picker-automatic>
            <input type="hidden" name="point_manual" value="${manual}" data-point-picker-manual>
            <input type="hidden" name="point_address" value="${pointAddress}" data-point-picker-address>
            <div data-point-picker-fields>
                <input id="place-latitude" name="latitude" value="${lat}" data-point-picker-latitude>
                <input id="place-longitude" name="longitude" value="${lng}" data-point-picker-longitude>
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
    const state = { markers: [], clickHandler: null, views: [], bounds: [], maps: 0, invalidated: 0 };
    const map = {
        setView: (center, zoom) => { state.views.push({ center, zoom }); return map; },
        fitBounds: (bounds) => { state.bounds.push(bounds); return map; },
        on: (event, handler) => { if (event === 'click') state.clickHandler = handler; return map; },
        invalidateSize: () => { state.invalidated++; return map; },
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
    const field = /** @type {HTMLInputElement} */ (document.getElementById('place-address'));
    field.value = address;
    field.dispatchEvent(new Event('change'));
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/map.js');
    await import('../../public/assets/js/point-picker.js');
}

const value = (id) => /** @type {HTMLInputElement} */ (document.getElementById(id)).value;
const handle = () => window.ScoutMagicPointPicker.wire(document.querySelector('[data-point-picker]'));

describe('point-picker', () => {
    beforeEach(() => {
        delete window.L;
        delete window.ScoutMagicMap;
        delete window.ScoutMagicPointPicker;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('the component', () => {
        it('hands back no handle when the page cannot draw a map', async () => {
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            expect(handle()).toBeNull();
            expect(window.ScoutMagicPointPicker.wire(null)).toBeNull();
        });

        it('wires a picker once, whoever asks for it', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            expect(handle()).toBe(handle());
            expect(leaflet.maps).toBe(1);
        });

        it('names the pin and the address ring with the words the page gave it', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: true, labels: 'data-pin-label="Entrée du terrain" data-address-label="Adresse <du> lieu"' });
            await load();
            answer({ success: true, found: true, latitude: 50.125, longitude: 5.187 });

            leave('Rue des Grottes 12, Han');
            await settle();

            expect(pinOf(leaflet).options.title).toBe('Entrée du terrain — faites-le glisser pour le déplacer');
            expect(pinOf(leaflet).options.alt).toBe('Entrée du terrain');
            expect(addressMarkerOf(leaflet).options.title).toBe('Adresse <du> lieu');
            // Escaped: the ring's label is text, never markup.
            expect(addressMarkerOf(leaflet).options.icon.divIcon.html).toContain('Adresse &lt;du&gt; lieu');
        });

        it('composes an address kept in several fields, the way the server writes it', async () => {
            stubLeaflet();
            document.body.innerHTML = `
                <input id="a-street" value=""><input id="a-postal" value=""><input id="a-city" value="">
                <input id="a-country" value="Belgique">
                <fieldset data-point-picker data-locate-url="/lookup/address"
                          data-address-fields='[["a-street"],["a-postal","a-city"],["a-country"]]'>
                    <input type="hidden" data-point-picker-automatic>
                    <input type="hidden" data-point-picker-manual value="0">
                    <input type="hidden" data-point-picker-address>
                    <div data-point-picker-fields>
                        <input id="a-lat" data-point-picker-latitude><input id="a-lng" data-point-picker-longitude>
                    </div>
                    <div class="d-none" data-point-picker-map-box><div data-point-picker-map></div>
                        <button type="button" data-point-picker-remove>Retirer</button></div>
                    <button type="button" class="d-none" data-point-picker-place>Placer</button>
                </fieldset>`;
            await load();
            answer({ success: true, found: true, latitude: 50.4421, longitude: 4.9876 });

            /** @type {HTMLInputElement} */ (document.getElementById('a-street')).value = 'Rue du Tronquoy 4';
            /** @type {HTMLInputElement} */ (document.getElementById('a-postal')).value = '5340';
            const city = /** @type {HTMLInputElement} */ (document.getElementById('a-city'));
            city.value = 'Mozet';
            city.dispatchEvent(new Event('change'));
            await settle();

            expect(window.ScoutMagicApi.getJson).toHaveBeenLastCalledWith(
                '/lookup/address?q=' + encodeURIComponent('Rue du Tronquoy 4, 5340 Mozet, Belgique')
            );
            expect(document.querySelector('[data-point-picker-address]').value).toBe('Rue du Tronquoy 4, 5340 Mozet, Belgique');
            expect(value('a-lat')).toBe('50.442100');
        });

        it('redraws the map when the page says its box changed size', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();
            const views = leaflet.views.length;

            handle().refresh();

            expect(leaflet.invalidated).toBeGreaterThan(1);
            expect(leaflet.views.length).toBe(views + 1);
            expect(leaflet.views.at(-1)).toEqual({ center: [50.72, 4.64], zoom: 15 });
        });

        it('redraws the address with a hand-placed pin, as the lookup framed them', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.2', lng: '5.2', manual: '1', locate: true });
            await load();
            answer({ success: true, found: true, latitude: 50.4, longitude: 4.9 });
            leave('Place du Marché 1, Namur');
            await settle();
            const bounds = leaflet.bounds.length;

            handle().refresh();

            expect(leaflet.bounds.length).toBe(bounds + 1);
            expect(leaflet.bounds.at(-1)).toEqual([[50.2, 5.2], [50.4, 4.9]]);
        });

        it('wires a picker the page asks for once Leaflet has arrived', async () => {
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();
            expect(handle()).toBeNull();

            const leaflet = stubLeaflet();

            expect(handle()).not.toBeNull();
            expect(leaflet.maps).toBe(1);
            expect(document.querySelector('[data-point-picker-fields]').classList.contains('d-none')).toBe(true);
        });
    });

    describe('the point', () => {
        it('leaves the two coordinate fields alone when Leaflet is not there', async () => {
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            expect(document.querySelector('[data-point-picker-fields]').classList.contains('d-none')).toBe(false);
        });

        it('shows a draggable pin, and a drag makes it a hand-placed point', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.718400', lng: '4.635900' });
            await load();

            expect(document.querySelector('[data-point-picker-fields]').classList.contains('d-none')).toBe(true);
            expect(document.querySelector('[data-point-picker-origin]').textContent).toBe('trouvé depuis l\'adresse');

            leaflet.markers[0].drag([50.7201, 4.6412]);

            expect(value('place-latitude')).toBe('50.720100');
            expect(value('place-longitude')).toBe('4.641200');
            expect(document.querySelector('[data-point-picker-origin]').textContent).toBe('point placé à la main');
        });

        it('places a pin with a click when there was none', async () => {
            const leaflet = stubLeaflet();
            buildForm();
            await load();

            /** @type {HTMLButtonElement} */ (document.querySelector('[data-point-picker-place]')).click();
            leaflet.clickHandler({ latlng: { lat: 50.1, lng: 4.2 } });

            expect(value('place-latitude')).toBe('50.100000');
            expect(value('place-longitude')).toBe('4.200000');
        });

        it('removes the point and empties the fields the form posts', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.72', lng: '4.64' });
            await load();

            /** @type {HTMLButtonElement} */ (document.querySelector('[data-point-picker-remove]')).click();

            expect(leaflet.markers[0].removed).toBe(true);
            expect(value('place-latitude')).toBe('');
            expect(value('place-longitude')).toBe('');
            expect(document.querySelector('[data-point-picker-place]').classList.contains('d-none')).toBe(false);
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
                '/lookup/address?q=G%C3%AEte%20de%20Han%2C%20rue%20des%20Grottes%2012'
            );
            expect(document.querySelector('[data-point-picker-map-box]').classList.contains('d-none')).toBe(false);
            expect(document.querySelector('[data-point-picker-place]').classList.contains('d-none')).toBe(true);
            expect(addressMarkerOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
            expect(addressMarkerOf(leaflet).options.interactive).toBe(false);
            expect(pinOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
            expect(leaflet.views.at(-1)).toEqual({ center: [50.125, 5.187], zoom: 15 });
            expect(value('place-latitude')).toBe('50.125000');
            expect(value('place-longitude')).toBe('5.187000');
            expect(document.querySelector('[data-point-picker-automatic]').value).toBe('1');
            expect(document.querySelector('[data-point-picker-address]').value).toBe('Gîte de Han, rue des Grottes 12');
            expect(document.querySelector('[data-point-picker-origin]').textContent).toBe('trouvé depuis l\'adresse');
        });

        it('tells the server which address an untouched pin was found for, even before a new lookup answers', async () => {
            // Lookups that never answer: the form is sent while they run.
            vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
            try {
                stubLeaflet();
                buildForm({ lat: '50.7', lng: '4.6', address: 'Plaine de Basse-Wavre', locate: true });
                await load();
                expect(document.querySelector('[data-point-picker-address]').value).toBe('Plaine de Basse-Wavre');

                leave('Gîte de Han, rue des Grottes 12');
                expect(value('place-latitude')).toBe('50.700000');
                expect(document.querySelector('[data-point-picker-automatic]').value).toBe('1');
                expect(document.querySelector('[data-point-picker-address]').value).toBe('Plaine de Basse-Wavre');
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('keeps the address a refused form says its pin was found for, and drops that pin when the new one is not found', async () => {
            // Sent before the lookup of B answered, refused for another
            // reason: the pin is still A's, whatever the field now says.
            vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.7', lng: '4.6', address: 'Gîte de Han', pointAddress: 'Plaine de Basse-Wavre', locate: true });
            await load();
            vi.unstubAllGlobals();
            expect(document.querySelector('[data-point-picker-address]').value).toBe('Plaine de Basse-Wavre');

            answer({ success: true, found: false });
            leave('Gîte de Han');
            await settle();

            expect(pinOf(leaflet)).toBeUndefined();
            expect(value('place-latitude')).toBe('');
        });

        it('keeps a pin moved by hand where it is, and moves the address marker', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: true });
            await load();
            answer(FOUND);
            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            pinOf(leaflet).drag([50.2, 5.2]);
            expect(document.querySelector('[data-point-picker-automatic]').value).toBe('0');
            expect(document.querySelector('[data-point-picker-address]').value).toBe('');

            answer({ success: true, found: true, latitude: 50.4, longitude: 4.9 });
            leave('Place du Marché 1, Namur');
            await settle();

            expect(pinOf(leaflet).getLatLng()).toEqual({ lat: 50.2, lng: 5.2 });
            expect(addressMarkerOf(leaflet).getLatLng()).toEqual({ lat: 50.4, lng: 4.9 });
            expect(leaflet.bounds.at(-1)).toEqual([[50.2, 5.2], [50.4, 4.9]]);
            expect(value('place-latitude')).toBe('50.200000');
            expect(document.querySelector('[data-point-picker-automatic]').value).toBe('0');
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
            expect(value('place-latitude')).toBe('');
            expect(document.querySelector('[data-point-picker-automatic]').value).toBe('1');
            expect(document.querySelector('[data-point-picker-place]').classList.contains('d-none')).toBe(false);
        });

        it('drops a saved automatic pin when the address changes and the lookup is switched off', async () => {
            const leaflet = stubLeaflet();
            buildForm({ locate: false, address: 'Gîte de Han', lat: '50.125000', lng: '5.187000' });
            await load();
            window.ScoutMagicApi.getJson = vi.fn();

            leave('Place du Marché 1, Namur');

            expect(window.ScoutMagicApi.getJson).not.toHaveBeenCalled();
            expect(pinOf(leaflet)).toBeUndefined();
            expect(value('place-latitude')).toBe('');
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
                expect(value('place-latitude')).toBe('50.125000');
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('looks the address up when the form opens with one', async () => {
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
                expect(fetch.mock.calls[0][0]).toBe('/lookup/address?q=G%C3%AEte%20de%20Han');
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('looks the address up again when the page asks — a field it filled itself', async () => {
            stubLeaflet();
            buildForm({ locate: true });
            await load();
            answer(FOUND);
            /** @type {HTMLInputElement} */ (document.getElementById('place-address')).value = 'Plaine de Basse-Wavre';

            handle().locate();
            await settle();

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledWith('/lookup/address?q=Plaine%20de%20Basse-Wavre');
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

        it('leaves a point the person removed removed, and opens « Placer » on the address', async () => {
            const leaflet = stubLeaflet();
            buildForm({ lat: '50.72', lng: '4.64', locate: true });
            await load();
            /** @type {HTMLButtonElement} */ (document.querySelector('[data-point-picker-remove]')).click();

            answer(FOUND);
            leave('Gîte de Han, rue des Grottes 12');
            await settle();

            expect(document.querySelector('[data-point-picker-map-box]').classList.contains('d-none')).toBe(true);
            expect(value('place-latitude')).toBe('');
            expect(document.querySelector('[data-point-picker-manual]').value).toBe('1');

            /** @type {HTMLButtonElement} */ (document.querySelector('[data-point-picker-place]')).click();
            expect(leaflet.views.at(-1)).toEqual({ center: [50.125, 5.187], zoom: 15 });
            expect(addressMarkerOf(leaflet).getLatLng()).toEqual({ lat: 50.125, lng: 5.187 });
        });
    });

    // Issue #692: a lookup that places nothing used to say nothing, and a
    // venue name the map does not know looked like a lookup that never ran.
    describe('the sentence under the address', () => {
        const status = () => /** @type {HTMLElement} */ (document.querySelector('#place-address-status'));

        beforeEach(async () => {
            stubLeaflet();
            buildForm({ locate: true });
            await load();
        });

        it('says an address the map does not know is not found', async () => {
            answer({ success: true, found: false, reason: 'not_found' });
            leave('Camp scout de la Fresnaye');
            await settle();

            expect(status().classList.contains('d-none')).toBe(false);
            expect(status().textContent).toBe('Adresse introuvable sur la carte : vérifiez-la, ou placez le point à la main.');
        });

        it('says so differently when the lookup could not run', async () => {
            answer({ success: true, found: false, reason: 'unavailable' });
            leave('Rue des Grottes 12, Han');
            await settle();

            expect(status().textContent).toBe('La carte n’a pas pu chercher cette adresse pour l’instant : placez le point à la main.');
        });

        it('says nothing for a request a newer one replaced', async () => {
            answer({ success: true, found: false, reason: 'superseded' });
            leave('Rue des Grottes 12, Han');
            await settle();

            expect(status().classList.contains('d-none')).toBe(true);
            expect(status().textContent).toBe('');
        });

        it('goes away once an address is found, or the field is emptied', async () => {
            answer({ success: true, found: false, reason: 'not_found' });
            leave('Camp scout de la Fresnaye');
            await settle();

            answer({ success: true, found: true, latitude: 50.125, longitude: 5.187 });
            leave('Rue des Grottes 12, Han');
            await settle();
            expect(status().classList.contains('d-none')).toBe(true);

            answer({ success: true, found: false, reason: 'not_found' });
            leave('Camp scout de la Fresnaye');
            await settle();
            expect(status().classList.contains('d-none')).toBe(false);

            leave('');
            expect(status().classList.contains('d-none')).toBe(true);
        });
    });
});
