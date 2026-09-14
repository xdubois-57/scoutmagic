// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/storage-locations.js (imported below,
// never reimplemented here). That file is a plain IIFE that reads the DOM at
// import time rather than waiting for DOMContentLoaded, so every test builds
// its DOM first and then imports the module through a reset registry.
//
// Split out of gallery-storage-location.test.js in IT-02, when the locations
// left the gallery's configuration page for /config/stockage. The album
// migration stayed behind and has a spec of its own.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadScript() {
    vi.resetModules();
    // The real site-wide toolboxes, loaded by base.html.twig before every
    // page script — same order here.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/storage-locations.js');
}

function renderLocationForm(locationId = '') {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="storage-location-local"></div>
        <div class="storage-location-s3">
            <select id="s3-provider"><option value="custom" selected>Personnalisé</option></select>
            <input id="s3-endpoint" value="https://s3.example.org">
            <input id="s3-region" value="eu">
            <input id="s3-bucket" value="scoutmagic">
            <input id="s3-access-key" value="AK">
            <input id="s3-secret-key" type="password" value="">
            <button id="s3-test-connection" data-location-id="${locationId}"></button>
            <div id="s3-test-result"></div>
        </div>
    `;
}

function renderLocationsTable() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <table><tbody><tr>
            <td class="storage-location-status" data-location-id="4"></td>
            <td><button class="storage-location-test" data-id="4"></button></td>
        </tr></tbody></table>
    `;
}

describe('storage-locations.js test-connection', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
        document.body.innerHTML = '';
    });

    // Regression: the edit form deliberately leaves the secret blank ("laisser
    // vide pour conserver la clé actuelle"), so without the location id the
    // server received an empty secret and the test could only ever fail on
    // authentication.
    it('sends the location id so the server can reuse the stored secret', async () => {
        renderLocationForm('12');
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));
        await loadScript();

        document.getElementById('s3-test-connection').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        const body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(fetch.mock.calls[0][0]).toBe('/config/stockage/test-connexion');
        expect(body.location_id).toBe(12);
        expect(body.secret_key).toBe('');
        expect(body._csrf_token).toBe('tok');
    });

    it('sends location id 0 on the creation form, where there is nothing stored yet', async () => {
        renderLocationForm('');
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));
        await loadScript();

        document.getElementById('s3-test-connection').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        expect(JSON.parse(fetch.mock.calls[0][1].body).location_id).toBe(0);
    });

    it('sends a freshly typed secret as-is', async () => {
        renderLocationForm('12');
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));
        await loadScript();
        document.getElementById('s3-secret-key').value = 'nouvelle-cle';

        document.getElementById('s3-test-connection').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        expect(JSON.parse(fetch.mock.calls[0][1].body).secret_key).toBe('nouvelle-cle');
    });
});

describe('storage-locations.js error rendering', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
        document.body.innerHTML = '';
    });

    // Regression: escapeHtml() escaped &, < and > but not quotes, while being
    // interpolated straight into title="..." — a provider error message
    // containing a double quote broke out of the attribute.
    it('does not let a quote in a provider error break out of the title attribute', async () => {
        renderLocationsTable();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, ok: false, error: '" onmouseover="alert(1)' }),
        }));
        await loadScript();

        document.querySelector('.storage-location-test').click();
        const cell = document.querySelector('.storage-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        const badge = cell.querySelector('span');
        expect(badge).not.toBeNull();
        // The whole message stayed inside the title attribute; no extra
        // attribute was created out of it.
        expect(badge.getAttribute('title')).toBe('" onmouseover="alert(1)');
        expect(badge.hasAttribute('onmouseover')).toBe(false);
        expect(badge.attributes).toHaveLength(2);
    });

    it('renders an angle-bracket error as text, never as markup', async () => {
        renderLocationsTable();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: false, error: '<img src=x onerror=alert(1)>' }),
        }));
        await loadScript();

        document.querySelector('.storage-location-test').click();
        const cell = document.querySelector('.storage-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        expect(cell.querySelector('img')).toBeNull();
        expect(cell.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('shows the success badge when the location answers ok', async () => {
        renderLocationsTable();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, ok: true }) }));
        await loadScript();

        document.querySelector('.storage-location-test').click();
        const cell = document.querySelector('.storage-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        expect(cell.textContent).toContain('Joignable');
    });

    it('renders a missing error message as an empty title rather than "undefined"', async () => {
        renderLocationsTable();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, ok: false }) }));
        await loadScript();

        document.querySelector('.storage-location-status');
        document.querySelector('.storage-location-test').click();
        const cell = document.querySelector('.storage-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        expect(cell.querySelector('span').getAttribute('title')).toBe('');
    });
});

describe('storage-locations.js « hors de storage/ » warning', () => {
    function renderPathField() {
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        document.body.innerHTML = `
            <input id="storage-local-subdir" value="">
            <div id="storage-local-outside-warning" class="d-none"></div>
        `;
    }

    beforeEach(() => {
        vi.restoreAllMocks();
        renderPathField();
    });

    async function warningShownFor(value) {
        const input = /** @type {HTMLInputElement} */ (document.getElementById('storage-local-subdir'));
        input.value = value;
        await loadScript();
        input.dispatchEvent(new Event('input'));

        return !document.getElementById('storage-local-outside-warning').classList.contains('d-none');
    }

    it('stays hidden for a folder inside storage/', async () => {
        expect(await warningShownFor('gallery/photos')).toBe(false);
    });

    it('warns on a Unix absolute path', async () => {
        expect(await warningShownFor('/mnt/nas/photos')).toBe(true);
    });

    it('warns on a Windows drive path', async () => {
        expect(await warningShownFor('D:\\photos')).toBe(true);
    });

    // The one that looks like an oversight: a UNC path starts with neither
    // a slash nor a drive letter, yet it names another machine entirely —
    // as far outside storage/ as a path can get.
    it('warns on a Windows UNC path', async () => {
        expect(await warningShownFor('\\\\nas\\photos')).toBe(true);
    });

    it('warns on a UNC path typed with surrounding spaces', async () => {
        expect(await warningShownFor('  \\\\nas\\photos  ')).toBe(true);
    });
});
