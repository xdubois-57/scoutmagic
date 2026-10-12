// Isolated JavaScript unit test — jsdom only. Exercises the REAL
// public/assets/js/registration-copy-settings.js (imported below, never
// reimplemented here) on the markup of the box « Copie des e-mails
// d'inscription » of Formulaire d'inscription › Réglages
// (modules/registration/views/config/_settings_copy.html.twig, issue #843
// IT-06).
//
// What is pinned: the address field follows the switch — disabled, and
// therefore not submitted, while the copy is off; usable again the moment
// it is switched back on — and the state the page was rendered in is
// applied on load.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function box({ enabled = true } = {}) {
    return `<form method="post" action="/config/inscriptions/copie" id="registration-email-copy">
        <input type="checkbox" id="registration-cc-enabled" name="cc_enabled" value="1"
               data-email-copy-switch ${enabled ? 'checked' : ''}>
        <input type="email" id="registration-cc-address" name="cc_address" value="unite@exemple.be"
               data-email-copy-address="1">
        <button type="submit">Enregistrer</button>
    </form>`;
}

async function load(markup) {
    document.body.innerHTML = markup;
    vi.resetModules();
    await import('../../public/assets/js/registration-copy-settings.js');

    return {
        toggle: /** @type {HTMLInputElement} */ (document.getElementById('registration-cc-enabled')),
        address: /** @type {HTMLInputElement} */ (document.getElementById('registration-cc-address')),
    };
}

describe('registration-copy-settings.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('leaves the address usable while the copy is on', async () => {
        const { address } = await load(box({ enabled: true }));

        expect(address.disabled).toBe(false);
    });

    it('disables the address on load when the copy is off', async () => {
        const { address } = await load(box({ enabled: false }));

        expect(address.disabled).toBe(true);
    });

    it('follows the switch both ways, keeping what was typed', async () => {
        const { toggle, address } = await load(box({ enabled: true }));

        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        expect(address.disabled).toBe(true);
        expect(address.value).toBe('unite@exemple.be');

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        expect(address.disabled).toBe(false);
    });

    it('a disabled address is not part of what the form submits', async () => {
        const { toggle } = await load(box({ enabled: true }));
        const form = /** @type {HTMLFormElement} */ (document.getElementById('registration-email-copy'));

        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));

        expect(new FormData(form).has('cc_address')).toBe(false);
    });

    it('does nothing on a page without the box', async () => {
        await load('<p>Autre page</p>');

        expect(window.ScoutMagicRegistrationCopySettings?.syncAddress).toBeTypeOf('function');
    });
});
