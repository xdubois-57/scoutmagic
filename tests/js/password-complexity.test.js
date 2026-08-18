// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/password-complexity.js (imported below, never
// reimplemented here) via the `globalThis.initPasswordComplexityChecklist`
// hook that file exposes for exactly this purpose (see the comment at the
// bottom of that file).
import { beforeEach, describe, expect, it } from 'vitest';
import '../../public/assets/js/password-complexity.js';

describe('password-complexity.js: initPasswordComplexityChecklist', () => {
    const RULES = ['length', 'uppercase', 'lowercase', 'digit', 'symbol'];

    beforeEach(() => {
        document.body.innerHTML = `
            <input id="pwd" type="password">
            <ul id="checklist">
                <li data-rule="length"><i></i></li>
                <li data-rule="uppercase"><i></i></li>
                <li data-rule="lowercase"><i></i></li>
                <li data-rule="digit"><i></i></li>
                <li data-rule="symbol"><i></i></li>
            </ul>
        `;
    });

    it('returns null (defensive no-op) when the input element is missing from the DOM', () => {
        document.body.innerHTML = '<ul id="checklist"></ul>';

        expect(globalThis.initPasswordComplexityChecklist('missing-input', 'checklist')).toBeNull();
    });

    it('returns null (defensive no-op) when the checklist element is missing from the DOM', () => {
        document.body.innerHTML = '<input id="pwd">';

        expect(globalThis.initPasswordComplexityChecklist('pwd', 'missing-list')).toBeNull();
    });

    it('reports a password satisfying every complexity rule as valid, marking each rule met in the DOM', () => {
        const checklist = globalThis.initPasswordComplexityChecklist('pwd', 'checklist');
        const input = document.getElementById('pwd');

        input.value = 'Abcdefghij1!';
        input.dispatchEvent(new Event('input'));

        expect(checklist.isValid()).toBe(true);
        RULES.forEach((rule) => {
            const li = document.querySelector('[data-rule="' + rule + '"]');
            expect(li.classList.contains('text-success')).toBe(true);
            expect(li.classList.contains('text-body-secondary')).toBe(false);
            expect(li.querySelector('i').className).toContain('bi-check-circle-fill');
        });
    });

    it('reports a too-short, all-lowercase password as invalid, leaving unmet rules unmarked', () => {
        const checklist = globalThis.initPasswordComplexityChecklist('pwd', 'checklist');
        const input = document.getElementById('pwd');

        input.value = 'abcdef';
        input.dispatchEvent(new Event('input'));

        expect(checklist.isValid()).toBe(false);
        ['length', 'uppercase', 'digit', 'symbol'].forEach((rule) => {
            const li = document.querySelector('[data-rule="' + rule + '"]');
            expect(li.classList.contains('text-body-secondary')).toBe(true);
            expect(li.querySelector('i').className).toContain('bi-circle');
        });
        // The one rule this password DOES satisfy is still marked met.
        const lowercaseLi = document.querySelector('[data-rule="lowercase"]');
        expect(lowercaseLi.classList.contains('text-success')).toBe(true);
    });

    it('re-renders the checklist live on every input event, without a page reload', () => {
        const checklist = globalThis.initPasswordComplexityChecklist('pwd', 'checklist');
        const input = document.getElementById('pwd');

        input.value = 'abcdef';
        input.dispatchEvent(new Event('input'));
        expect(checklist.isValid()).toBe(false);

        input.value = 'Abcdefghij1!';
        input.dispatchEvent(new Event('input'));
        expect(checklist.isValid()).toBe(true);
    });
});
