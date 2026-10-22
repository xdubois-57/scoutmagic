// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/rental-inventory-sign.js: on the inventory's signing
// screen (#989, lot E), the renter's choice sets their signature aside —
// by disabling its fieldset, the one way signature-dialog.js knows — and
// says what the inventory loses without it.
import { afterEach, describe, expect, it } from 'vitest';
import { applyRenterChoice, wire } from '../../public/assets/js/rental-inventory-sign.js';

function page(checked = 'on_site') {
    document.body.innerHTML = `
        <form data-inventory-signing-form data-signature-inline-form>
            <input type="radio" name="renter_signing" value="on_site" ${checked === 'on_site' ? 'checked' : ''}>
            <input type="radio" name="renter_signing" value="absent" ${checked === 'absent' ? 'checked' : ''}>
            <input type="radio" name="renter_signing" value="refused" ${checked === 'refused' ? 'checked' : ''}>
            <p data-renter-unsigned-warning hidden>Sans la signature du locataire…</p>
            <fieldset data-renter-signature>
                <div data-signature-field data-signature-mode="witness">
                    <input type="text" name="renter_signer_name" required>
                    <input type="hidden" name="renter_signature_data" value="">
                </div>
            </fieldset>
            <div data-signature-field data-signature-mode="new">
                <input type="hidden" name="signature_data" value="">
            </div>
        </form>`;

    return /** @type {HTMLFormElement} */ (document.querySelector('form'));
}

const fieldset = () => /** @type {HTMLFieldSetElement} */ (document.querySelector('fieldset[data-renter-signature]'));
const warning = () => /** @type {HTMLElement} */ (document.querySelector('[data-renter-unsigned-warning]'));

afterEach(() => {
    document.body.innerHTML = '';
});

describe('applyRenterChoice()', () => {
    it('keeps the renter\'s fields when they sign here, without the warning', () => {
        const form = page('on_site');
        applyRenterChoice(form);

        expect(fieldset().disabled).toBe(false);
        expect(warning().hidden).toBe(true);
    });

    it.each(['absent', 'refused'])('sets them aside and warns when the renter is %s', (choice) => {
        const form = page(choice);
        applyRenterChoice(form);

        expect(fieldset().disabled).toBe(true);
        expect(warning().hidden).toBe(false);
        // A disabled fieldset takes the required name out of the browser's
        // validation and out of what is sent.
        expect(/** @type {HTMLInputElement} */ (form.querySelector('[name="renter_signer_name"]')).willValidate)
            .toBe(false);
        expect(new FormData(form).has('renter_signer_name')).toBe(false);
        // The manager's own field is untouched.
        expect(new FormData(form).has('signature_data')).toBe(true);
    });
});

describe('the page', () => {
    it('follows the choice as it changes, both ways', () => {
        page('on_site');
        wire(document);

        const absent = /** @type {HTMLInputElement} */ (document.querySelector('input[value="absent"]'));
        absent.checked = true;
        absent.dispatchEvent(new Event('change', { bubbles: true }));
        expect(fieldset().disabled).toBe(true);
        expect(warning().hidden).toBe(false);

        const here = /** @type {HTMLInputElement} */ (document.querySelector('input[value="on_site"]'));
        here.checked = true;
        here.dispatchEvent(new Event('change', { bubbles: true }));
        expect(fieldset().disabled).toBe(false);
        expect(warning().hidden).toBe(true);
    });

    it('applies the choice a restored page comes back with', () => {
        page('on_site');
        wire(document);
        /** @type {HTMLInputElement} */ (document.querySelector('input[value="refused"]')).checked = true;

        globalThis.dispatchEvent(new Event('pageshow'));

        expect(fieldset().disabled).toBe(true);
    });
});
