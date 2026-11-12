// Isolated JavaScript unit test — jsdom only. Exercises the REAL helpers of
// public/assets/js/data-migration.js: the key a deposit is remembered
// under, the percentage, when a poll answer means the page must be drawn
// again, and the check that keeps every request on the module's own
// endpoints whatever the page's data island says.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function boot(island) {
    vi.resetModules();
    document.body.innerHTML = island
        ? '<script type="application/json" id="data-migration-page">' + JSON.stringify(island) + '</script>'
        : '';
    window.ScoutMagicApi = {
        pageData: (id) => {
            const el = document.getElementById(id);
            return el ? JSON.parse(el.textContent) : null;
        },
        pollSlot: () => ({ start: vi.fn(), stop: vi.fn(), isRunning: () => false }),
        poll: vi.fn(() => ({ stop: vi.fn() })),
        postJson: vi.fn(),
        csrfToken: () => 'tok',
    };
    await import('../../public/assets/js/data-migration.js');
    return window.ScoutMagicDataMigration;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('data-migration.js helpers', () => {
    it('remembers a deposit per import and per file', async () => {
        const dm = await boot(null);
        const a = dm.uploadKey('photos', { name: 'a.zip', size: 10, lastModified: 1 });
        expect(a).toBe('scoutmagic:data-migration:photos:10:1:a.zip');
        expect(dm.uploadKey('galeries', { name: 'a.zip', size: 10, lastModified: 1 })).not.toBe(a);
        expect(dm.uploadKey('photos', { name: 'a.zip', size: 11, lastModified: 1 })).not.toBe(a);
    });

    it('computes a whole percentage bounded to 0..100', async () => {
        const dm = await boot(null);
        expect(dm.percentOf(0, 0)).toBe(0);
        expect(dm.percentOf(1, 3)).toBe(33);
        expect(dm.percentOf(5, 4)).toBe(100);
        expect(dm.percentOf(-1, 4)).toBe(0);
    });

    it('reloads only when the run left the state the page was drawn for', async () => {
        const dm = await boot(null);
        expect(dm.needsReload('uploaded', { state: 'uploaded' })).toBe(false);
        expect(dm.needsReload('uploaded', { state: 'analysed' })).toBe(true);
        expect(dm.needsReload('running', { state: 'done' })).toBe(true);
        expect(dm.needsReload('running', { state: 'interrupted' })).toBe(true);
        // A confirmed run turns `running` on its first slice: same screen.
        expect(dm.needsReload('confirmed', { state: 'running' })).toBe(false);
        expect(dm.needsReload('running', null)).toBe(false);
        expect(dm.needsReload('running', {})).toBe(false);
    });

    it('accepts only the module own endpoints as request targets', async () => {
        const dm = await boot(null);
        expect(dm.isMigrationUrl('/config/migration-donnees/photos/avancer')).toBe(true);
        expect(dm.isMigrationUrl('/config/migration-donnees/photos/depot')).toBe(true);
        expect(dm.isMigrationUrl('//evil.example/config/migration-donnees/photos/avancer')).toBe(false);
        expect(dm.isMigrationUrl('javascript:alert(1)')).toBe(false);
        expect(dm.isMigrationUrl('/admin/migration-donnees/photos/avancer')).toBe(false);
        expect(dm.isMigrationUrl('/config/migration-donnees/../maintenance/avancer')).toBe(false);
        expect(dm.isMigrationUrl(42)).toBe(false);
    });
});

describe('data-migration.js on a page', () => {
    it('drives the slices on the analysis step', async () => {
        await boot({
            importer: 'photos', step: 'analyse', state: 'uploaded', max_bytes: 100,
            urls: { upload: '/config/migration-donnees/photos/depot', advance: '/config/migration-donnees/photos/avancer' },
        });
        expect(window.ScoutMagicApi.poll).toHaveBeenCalledTimes(1);
    });

    it('does nothing when the island names a foreign endpoint', async () => {
        await boot({
            importer: 'photos', step: 'analyse', state: 'uploaded', max_bytes: 100,
            urls: { upload: 'https://evil.example/x', advance: '/config/migration-donnees/photos/avancer' },
        });
        expect(window.ScoutMagicApi.poll).not.toHaveBeenCalled();
    });

    it('posts one slice per tick and redraws the counters', async () => {
        document.body.innerHTML = '';
        await boot({
            importer: 'photos', step: 'run', state: 'running', max_bytes: 100,
            urls: { upload: '/config/migration-donnees/photos/depot', advance: '/config/migration-donnees/photos/avancer' },
        });
        const counter = document.createElement('div');
        counter.id = 'data-migration-count-imported';
        document.body.appendChild(counter);
        window.ScoutMagicApi.postJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, state: 'running', imported: 7 } });

        const tick = window.ScoutMagicApi.poll.mock.calls[0][0];
        await expect(tick()).resolves.toBe(true);
        expect(window.ScoutMagicApi.postJson).toHaveBeenCalledWith('/config/migration-donnees/photos/avancer', {});
        expect(counter.textContent).toBe('7');
    });
});
