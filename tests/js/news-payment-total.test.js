// Isolated JavaScript unit test — jsdom only. Exercises the REAL
// implementation in public/assets/js/news-form-builder.js.
//
// A file of its own rather than a case inside news-form-builder.test.js,
// and the reason is structural: the live payment total is not reachable
// through that file's `ScoutMagicNewsFormBuilderInternals` seam. Its
// `recalcPayment` is a closure created only when the module finds
// `#news-payment-summary` in the DOM AT IMPORT TIME, inside the IIFE. A
// test that imports the module first and builds the DOM afterwards can
// never reach it — so the DOM is built at module scope here, before the
// dynamic imports below.
//
// What it pins (module spec §11.4): the public submission form's running
// total, which is what a family sees before they commit to paying. The
// arithmetic is read out of `data-price` and the typed quantity, and both
// reads are `Number.parseFloat` — a field left empty must count as zero
// rather than poison the total with NaN.
import { describe, expect, it } from 'vitest';

document.body.innerHTML = `
    <div id="news-payment-summary">
        <input class="news-number-field" data-price="12.50" data-label="Repas" value="2">
        <input class="news-number-field" data-price="3.25" data-label="Boisson" value="">
        <input class="news-number-field" data-label="Sans prix" value="9">
        <div id="news-payment-lines"></div>
        <span id="news-payment-total"></span>
    </div>
`;

// The site-wide toolboxes base.html.twig loads before every page script,
// in the same order.
await import('../../public/assets/js/api.js');
await import('../../public/assets/js/toast.js');
await import('../../public/assets/js/news-form-builder.js');

const total = () => document.getElementById('news-payment-total').textContent;
const lines = () => document.getElementById('news-payment-lines').innerHTML;
const field = (label) => /** @type {HTMLInputElement} */ (
    document.querySelector(`[data-label="${label}"]`)
);

describe('news-form-builder.js: live payment total', () => {
    it('totals the priced fields on load, in euros with a comma', () => {
        // 2 × 12,50 = 25,00. The empty « Boisson » contributes nothing, and
        // the field with no data-price is not a priced line at all.
        expect(total()).toBe('25,00');
    });

    it('counts an empty quantity as zero rather than as NaN', () => {
        // The whole point of `|| 0` behind the parseFloat: one blank field
        // must not turn the total into "NaN" in front of a family.
        expect(total()).not.toContain('NaN');
        expect(lines()).not.toContain('NaN');
    });

    it('ignores a field carrying no price', () => {
        expect(lines()).not.toContain('Sans prix');
    });

    it('recomputes when a quantity is typed', () => {
        const drink = field('Boisson');
        drink.value = '4';
        drink.dispatchEvent(new Event('input'));

        // 2 × 12,50 + 4 × 3,25 = 25,00 + 13,00 = 38,00
        expect(total()).toBe('38,00');
        expect(lines()).toContain('Boisson');
    });

    it('treats a non-numeric quantity as zero', () => {
        const meal = field('Repas');
        meal.value = 'beaucoup';
        meal.dispatchEvent(new Event('input'));

        // Only the drink line survives: 4 × 3,25 = 13,00.
        expect(total()).toBe('13,00');
        expect(total()).not.toContain('NaN');
    });
});
