// Isolated JavaScript unit test — jsdom only, no network. Exercises the
// REAL public/assets/js/map.js (imported below, never reimplemented): the
// core's map, which every page drawing one goes through.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function load() {
    vi.resetModules();
    delete window.ScoutMagicMap;
    await import('../../public/assets/js/map.js');
}

describe('map.js', () => {
    beforeEach(() => {
        delete window.L;
    });

    it('draws the tiles of the one provider the CSP allows, with its attribution', async () => {
        const calls = { tiles: [], views: [] };
        const map = { setView: (c, z) => { calls.views.push([c, z]); return map; } };
        window.L = {
            map: () => map,
            tileLayer: (url, options) => ({ addTo: () => calls.tiles.push([url, options]) }),
        };
        await load();

        const created = window.ScoutMagicMap.create(document.createElement('div'));

        expect(created).toBe(map);
        expect(calls.tiles).toHaveLength(1);
        expect(calls.tiles[0][0]).toBe('https://tile.openstreetmap.org/{z}/{x}/{y}.png');
        expect(calls.tiles[0][1].attribution).toContain('OpenStreetMap');
        expect(calls.views[0]).toEqual([[50.45, 4.87], 8]);
    });

    it('centres on the point it is given', async () => {
        const views = [];
        const map = { setView: (c, z) => { views.push([c, z]); return map; } };
        window.L = { map: () => map, tileLayer: () => ({ addTo: () => {} }) };
        await load();

        window.ScoutMagicMap.create(document.createElement('div'), { center: [50.72, 4.64], zoom: 15 });

        expect(views[0]).toEqual([[50.72, 4.64], 15]);
    });

    it('builds nothing when Leaflet did not load', async () => {
        await load();

        expect(window.ScoutMagicMap.create(document.createElement('div'))).toBeNull();
    });
});
