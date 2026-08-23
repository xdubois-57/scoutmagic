// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/gallery-storage-location.js (imported
// below, never reimplemented here). That file is a plain IIFE that reads the
// DOM at import time rather than waiting for DOMContentLoaded, so every test
// builds its DOM first and then imports the module through a reset registry.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadScript() {
    vi.resetModules();
    // The real site-wide toolboxes, loaded by base.html.twig before every
    // page script — same order here.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/gallery-storage-location.js');
}

function renderLocationForm(locationId = '') {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="gallery-storage-local"></div>
        <div class="gallery-storage-s3">
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
            <td class="gallery-location-status" data-location-id="4"></td>
            <td><button class="gallery-location-test" data-id="4"></button></td>
        </tr></tbody></table>
    `;
}

describe('gallery-storage-location.js test-connection', () => {
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
        expect(fetch.mock.calls[0][0]).toBe('/config/gallery/test-connection');
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

describe('gallery-storage-location.js error rendering', () => {
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

        document.querySelector('.gallery-location-test').click();
        const cell = document.querySelector('.gallery-location-status');
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

        document.querySelector('.gallery-location-test').click();
        const cell = document.querySelector('.gallery-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        expect(cell.querySelector('img')).toBeNull();
        expect(cell.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('shows the success badge when the location answers ok', async () => {
        renderLocationsTable();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, ok: true }) }));
        await loadScript();

        document.querySelector('.gallery-location-test').click();
        const cell = document.querySelector('.gallery-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        expect(cell.textContent).toContain('Disponible');
    });

    it('renders a missing error message as an empty title rather than "undefined"', async () => {
        renderLocationsTable();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, ok: false }) }));
        await loadScript();

        document.querySelector('.gallery-location-status');
        document.querySelector('.gallery-location-test').click();
        const cell = document.querySelector('.gallery-location-status');
        await vi.waitFor(() => expect(cell.innerHTML).not.toBe(''));

        expect(cell.querySelector('span').getAttribute('title')).toBe('');
    });
});

describe('gallery-storage-location.js confirmations', () => {
    function renderMigrationRow() {
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        document.body.innerHTML = `
            <table><tbody><tr>
                <td>
                    <select class="gallery-migrate-target" data-album-id="9">
                        <option value="4" selected>Autre emplacement</option>
                    </select>
                </td>
                <td><button class="gallery-migrate-start" data-album-id="9" data-url="/config/gallery/albums/9/migrate"></button></td>
            </tr></tbody></table>
            <button class="gallery-location-delete" data-id="4"></button>
        `;
    }

    beforeEach(() => {
        vi.restoreAllMocks();
        renderMigrationRow();
        // Both success paths reload the page; jsdom has no navigation.
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/gallery', reload: vi.fn() },
        });
        // The shared dialog is stubbed — what this block owns is that it is
        // asked, with the right words, before anything is sent.
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    it('asks before starting a migration, with a « Migrer » button and the non-destructive variant', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));
        await loadScript();

        document.querySelector('.gallery-migrate-start').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({
            message: 'Démarrer la migration de cet album vers cet autre emplacement ? L\'album sera indisponible pour les membres pendant l\'opération.',
            confirmLabel: 'Migrer',
            variant: 'primary',
        }));
        expect(fetch.mock.calls[0][0]).toBe('/config/gallery/albums/9/migrate');
        expect(JSON.parse(fetch.mock.calls[0][1].body).target_location_id).toBe(4);
    });

    it('starts no migration when the confirmation is declined', async () => {
        global.fetch = vi.fn();
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await loadScript();

        document.querySelector('.gallery-migrate-start').click();
        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await Promise.resolve();

        expect(fetch).not.toHaveBeenCalled();
        expect(/** @type {HTMLButtonElement} */ (document.querySelector('.gallery-migrate-start')).disabled).toBe(false);
    });

    it('asks « Supprimer » before deleting a storage location', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));
        await loadScript();

        document.querySelector('.gallery-location-delete').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({
            message: 'Supprimer cet emplacement de stockage ?',
            confirmLabel: 'Supprimer',
        }));
        expect(fetch.mock.calls[0][0]).toBe('/config/gallery/locations/4/delete');
    });

    it('deletes nothing when the deletion confirmation is declined', async () => {
        global.fetch = vi.fn();
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await loadScript();

        document.querySelector('.gallery-location-delete').click();
        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await Promise.resolve();

        expect(fetch).not.toHaveBeenCalled();
        expect(/** @type {HTMLButtonElement} */ (document.querySelector('.gallery-location-delete')).disabled).toBe(false);
    });
});
