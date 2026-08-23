// Isolated JavaScript unit test — jsdom-simulated DOM only, no network.
// Exercises the REAL implementation in
// public/assets/js/finance-import-form.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors modules/finance/views/import/form.html.twig: an
// account select whose options carry `data-first-import`, the opening
// balance field, and the hint line under it.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <select id="import-account">
        <option value="1" data-first-import="1" selected>Compte courant</option>
        <option value="2" data-first-import="0">Livret</option>
    </select>
    <input type="number" id="import-balance">
    <div class="form-text" id="import-balance-help"></div>`;

const REQUIRED = 'Premier import pour ce compte : le solde est obligatoire.';
const OPTIONAL = 'Facultatif — laissez vide pour recalculer le solde'
    + ' automatiquement depuis les mouvements.';

describe('finance-import-form.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = PAGE;
    });

    const boot = () => import('../../public/assets/js/finance-import-form.js');
    const select = () => document.getElementById('import-account');
    const balance = () => document.getElementById('import-balance');
    const hint = () => document.getElementById('import-balance-help').textContent;

    function pick(value) {
        select().value = value;
        select().dispatchEvent(new Event('change'));
    }

    it('does nothing at all on a page without the import form', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        await expect(boot()).resolves.toBeDefined();
    });

    it('requires the balance for the account the form opens on', async () => {
        await boot();

        expect(balance().required).toBe(true);
        expect(hint()).toBe(REQUIRED);
    });

    it('makes it optional as soon as an already-imported account is picked', async () => {
        await boot();
        pick('2');

        expect(balance().required).toBe(false);
        expect(hint()).toBe(OPTIONAL);
    });

    it('makes it required again when a first-import account comes back', async () => {
        await boot();
        pick('2');
        pick('1');

        expect(balance().required).toBe(true);
        expect(hint()).toBe(REQUIRED);
    });

    it('opens optional when the selected account has already been imported', async () => {
        select().value = '2';
        await boot();

        expect(balance().required).toBe(false);
        expect(hint()).toBe(OPTIONAL);
    });

    it('treats a select with nothing selected as not-a-first-import', async () => {
        document.body.innerHTML = `
            <select id="import-account"></select>
            <input type="number" id="import-balance">
            <div id="import-balance-help"></div>`;
        await boot();

        expect(balance().required).toBe(false);
        expect(hint()).toBe(OPTIONAL);
    });
});
