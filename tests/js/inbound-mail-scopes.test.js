import { describe, it, expect, beforeEach } from 'vitest';
import { applyVisibility, init } from '../../public/assets/js/inbound-mail-scopes.js';

/**
 * The mailbox scope form's progressive enhancement.
 *
 * What matters here is not that things are hidden — it is that hiding is
 * never allowed to change what the form submits. Both halves are rendered
 * and both are submitted whatever this script does; the server picks the
 * one the chosen purpose selects. A test that only checked `hidden` flags
 * would pass on a version that disabled the inputs, which would silently
 * post « Personne » for every module the operator never touched.
 */
function build(purpose = 'shared', analyzing = true) {
    document.body.innerHTML = `
        <form data-mailbox-scopes>
            <input type="radio" name="purpose" value="shared" data-scope-purpose
                   ${purpose === 'shared' ? 'checked' : ''}>
            <input type="radio" name="purpose" value="dedicated" data-scope-purpose
                   ${purpose === 'dedicated' ? 'checked' : ''}>
            <div data-scope-section="dedicated"><select name="dedicated_to"></select></div>
            <div data-scope-section="shared">
                <input type="checkbox" name="scope[rental][analyze]" value="1"
                       data-scope-analyze="rental" ${analyzing ? 'checked' : ''}>
                <div data-scope-read="rental">
                    <input type="radio" name="scope[rental][read]" value="relevant" checked>
                </div>
            </div>
        </form>`;

    return /** @type {HTMLFormElement} */ (document.querySelector('form'));
}

describe('inbound-mail-scopes', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('shows the per-module answers on a shared box and hides the dedicated one', () => {
        const form = build('shared');
        applyVisibility(form);

        expect(form.querySelector('[data-scope-section="shared"]').hidden).toBe(false);
        expect(form.querySelector('[data-scope-section="dedicated"]').hidden).toBe(true);
    });

    it('shows the dedicated answer and hides the per-module ones on a dedicated box', () => {
        const form = build('dedicated');
        applyVisibility(form);

        expect(form.querySelector('[data-scope-section="dedicated"]').hidden).toBe(false);
        expect(form.querySelector('[data-scope-section="shared"]').hidden).toBe(true);
    });

    it('hides « qui peut lire » for a module that does not analyse the box', () => {
        const form = build('shared', false);
        applyVisibility(form);

        expect(form.querySelector('[data-scope-read="rental"]').hidden).toBe(true);
    });

    it('never disables an input, so hiding cannot change what is submitted', () => {
        const form = build('dedicated', false);
        applyVisibility(form);

        form.querySelectorAll('input, select').forEach((field) => {
            expect(field.disabled).toBe(false);
        });
    });

    it('reacts to the purpose being changed', () => {
        const form = build('shared');
        init(document);

        const dedicated = form.querySelector('[data-scope-purpose][value="dedicated"]');
        dedicated.checked = true;
        dedicated.dispatchEvent(new Event('change', { bubbles: true }));

        expect(form.querySelector('[data-scope-section="dedicated"]').hidden).toBe(false);
        expect(form.querySelector('[data-scope-section="shared"]').hidden).toBe(true);
    });

    it('reacts to a module being switched on', () => {
        const form = build('shared', false);
        init(document);

        const toggle = form.querySelector('[data-scope-analyze="rental"]');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change', { bubbles: true }));

        expect(form.querySelector('[data-scope-read="rental"]').hidden).toBe(false);
    });

    it('does nothing at all when the page carries no such form', () => {
        document.body.innerHTML = '<form data-other></form>';

        expect(() => init(document)).not.toThrow();
    });
});
