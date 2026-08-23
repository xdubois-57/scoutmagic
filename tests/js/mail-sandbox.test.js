// Isolated JavaScript unit test — jsdom-simulated DOM only, no network.
// Exercises the REAL implementation in
// public/assets/js/mail-sandbox.js (imported below, never reimplemented
// here).
//
// This gate is convenience only: modules/test_tools compares the word
// again server-side and refuses the request outright when it does not
// match. What the test pins is that the button starts disabled and only
// opens on an exact match.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <form method="post" action="/test-tools/mail-sandbox/empty" id="sandbox-empty-form">
        <input type="text" id="sandbox-empty-keyword" name="confirmation"
               data-confirmation-word="VIDER" autocomplete="off">
        <button type="submit" id="sandbox-empty-submit" disabled>Tout supprimer</button>
    </form>`;

describe('mail-sandbox.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = PAGE;
    });

    const boot = () => import('../../public/assets/js/mail-sandbox.js');
    const field = () => document.getElementById('sandbox-empty-keyword');
    const submit = () => document.getElementById('sandbox-empty-submit');

    function type(value) {
        field().value = value;
        field().dispatchEvent(new Event('input'));
    }

    it('does nothing at all on a page without the gate', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        await expect(boot()).resolves.toBeDefined();
    });

    it('leaves the button disabled until the word is typed', async () => {
        await boot();
        type('VID');

        expect(submit().disabled).toBe(true);
    });

    it('opens the button on an exact match', async () => {
        await boot();
        type('VIDER');

        expect(submit().disabled).toBe(false);
    });

    it('is case-sensitive and whitespace-sensitive', async () => {
        await boot();

        type('vider');
        expect(submit().disabled).toBe(true);
        type('VIDER ');
        expect(submit().disabled).toBe(true);
    });

    it('closes it again when the word is edited away', async () => {
        await boot();
        type('VIDER');
        type('VIDE');

        expect(submit().disabled).toBe(true);
    });

    it('never opens when the field carries no word at all', async () => {
        field().removeAttribute('data-confirmation-word');
        await boot();
        type('VIDER');

        expect(submit().disabled).toBe(true);
    });
});
