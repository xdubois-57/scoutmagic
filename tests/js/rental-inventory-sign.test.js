// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/rental-inventory-sign.js: on the inventory's signing
// screen (#989, lot E), the renter's choice sets their signature aside —
// by disabling its fieldset, the one way signature-dialog.js knows — and
// says what the inventory loses without it; « Signé sur papier » (lot F)
// sets both signatures aside and asks for the photo of the signed sheet.
import { afterEach, describe, expect, it } from 'vitest';
import { applyRenterChoice, paperSheetReady, PAPER_SHEET_MISSING, wire } from '../../public/assets/js/rental-inventory-sign.js';

function page(checked = 'on_site') {
    document.body.innerHTML = `
        <form data-inventory-signing-form data-signature-inline-form>
            <input type="radio" name="renter_signing" value="on_site" ${checked === 'on_site' ? 'checked' : ''}>
            <input type="radio" name="renter_signing" value="absent" ${checked === 'absent' ? 'checked' : ''}>
            <input type="radio" name="renter_signing" value="refused" ${checked === 'refused' ? 'checked' : ''}>
            <input type="radio" name="renter_signing" value="paper" ${checked === 'paper' ? 'checked' : ''}>
            <p data-renter-unsigned-warning hidden>Sans la signature du locataire…</p>
            <fieldset data-paper-sheet disabled hidden>
                <div data-photo-tile>
                    <input type="file" name="paper_sheet" data-photo-carrier>
                    <output data-photo-status></output>
                </div>
            </fieldset>
            <fieldset data-renter-signature>
                <div data-signature-field data-signature-mode="witness">
                    <input type="text" name="renter_signer_name" required>
                    <input type="hidden" name="renter_signature_data" value="">
                </div>
            </fieldset>
            <fieldset data-manager-signature>
                <div data-signature-field data-signature-mode="new">
                    <input type="hidden" name="signature_data" value="">
                </div>
            </fieldset>
            <p data-manager-paper-note hidden>Signé sur papier : votre signature est sur la feuille.</p>
        </form>`;

    return /** @type {HTMLFormElement} */ (document.querySelector('form'));
}

const fieldset = () => /** @type {HTMLFieldSetElement} */ (document.querySelector('fieldset[data-renter-signature]'));
const warning = () => /** @type {HTMLElement} */ (document.querySelector('[data-renter-unsigned-warning]'));
const manager = () => /** @type {HTMLFieldSetElement} */ (document.querySelector('fieldset[data-manager-signature]'));
const paper = () => /** @type {HTMLFieldSetElement} */ (document.querySelector('fieldset[data-paper-sheet]'));
const paperNote = () => /** @type {HTMLElement} */ (document.querySelector('[data-manager-paper-note]'));

afterEach(() => {
    document.body.innerHTML = '';
});

describe('applyRenterChoice()', () => {
    it('keeps the renter\'s fields when they sign here, without the warning', () => {
        const form = page('on_site');
        applyRenterChoice(form);

        expect(fieldset().disabled).toBe(false);
        expect(warning().hidden).toBe(true);
        // The sheet's photo is not asked for.
        expect(paper().disabled).toBe(true);
        expect(paper().hidden).toBe(true);
        expect(/** @type {HTMLInputElement} */ (form.querySelector('[name="paper_sheet"]')).willValidate).toBe(false);
    });

    it('sets both signatures aside on paper and asks for the photo of the sheet instead', () => {
        const form = page('paper');
        applyRenterChoice(form);

        expect(fieldset().disabled).toBe(true);
        expect(fieldset().hidden).toBe(true);
        expect(manager().disabled).toBe(true);
        expect(manager().hidden).toBe(true);
        expect(paperNote().hidden).toBe(false);
        // The renter signed the sheet: no warning that they did not sign.
        expect(warning().hidden).toBe(true);
        expect(paper().disabled).toBe(false);
        expect(paper().hidden).toBe(false);
        expect(/** @type {HTMLInputElement} */ (form.querySelector('[name="paper_sheet"]')).willValidate).toBe(true);
        // Nothing of either electronic signature is sent.
        const sent = new FormData(form);
        expect(sent.has('renter_signer_name')).toBe(false);
        expect(sent.has('renter_signature_data')).toBe(false);
        expect(sent.has('signature_data')).toBe(false);
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

        const onPaper = /** @type {HTMLInputElement} */ (document.querySelector('input[value="paper"]'));
        onPaper.checked = true;
        onPaper.dispatchEvent(new Event('change', { bubbles: true }));
        expect(manager().disabled).toBe(true);
        expect(paper().disabled).toBe(false);

        const here = /** @type {HTMLInputElement} */ (document.querySelector('input[value="on_site"]'));
        here.checked = true;
        here.dispatchEvent(new Event('change', { bubbles: true }));
        expect(fieldset().disabled).toBe(false);
        expect(fieldset().hidden).toBe(false);
        expect(manager().disabled).toBe(false);
        expect(manager().hidden).toBe(false);
        expect(paper().disabled).toBe(true);
        expect(paperNote().hidden).toBe(true);
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

describe('paperSheetReady()', () => {
    /** @param {HTMLFormElement} form @param {number} count */
    function carry(form, count) {
        const carrier = /** @type {HTMLInputElement} */ (form.querySelector('[name="paper_sheet"]'));
        Object.defineProperty(carrier, 'files', { configurable: true, value: { length: count } });
    }
    const status = () => /** @type {HTMLElement} */ (document.querySelector('[data-photo-status]'));

    it('lets an electronic signing through: the sheet is not asked for', () => {
        const form = page('on_site');
        applyRenterChoice(form);

        expect(paperSheetReady(form)).toBe(true);
        expect(status().textContent).toBe('');
    });

    it('holds a paper signing without the sheet and says so in the tile', () => {
        const form = page('paper');
        applyRenterChoice(form);
        carry(form, 0);

        expect(paperSheetReady(form)).toBe(false);
        expect(status().textContent).toBe(PAPER_SHEET_MISSING);
    });

    it('lets a paper signing through once the sheet is carried', () => {
        const form = page('paper');
        applyRenterChoice(form);
        carry(form, 1);

        expect(paperSheetReady(form)).toBe(true);
    });

    it('stops the submit itself when wired', () => {
        const form = page('paper');
        wire(document);
        carry(form, 0);
        const event = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(status().textContent).toBe(PAPER_SHEET_MISSING);
    });
});
