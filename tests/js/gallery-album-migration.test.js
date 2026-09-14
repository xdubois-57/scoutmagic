// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/gallery-album-migration.js (imported
// below, never reimplemented here). That file is a plain IIFE that reads the
// DOM at import time rather than waiting for DOMContentLoaded, so every test
// builds its DOM first and then imports the module through a reset registry.
//
// What stayed here when IT-02 moved the storage locations to their own page:
// the migration is gallery work (D5) — it moves ALBUMS and their media, and
// an album means nothing to a storage location.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadScript() {
    vi.resetModules();
    // The real site-wide toolboxes, loaded by base.html.twig before every
    // page script — same order here.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/gallery-album-migration.js');
}

describe('gallery-album-migration.js confirmations', () => {
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
        `;
    }

    beforeEach(() => {
        vi.restoreAllMocks();
        renderMigrationRow();
        // The success path reloads the page; jsdom has no navigation.
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/gallery?onglet=albums', reload: vi.fn() },
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

});
