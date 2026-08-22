// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP server,
// no network. Exercises the REAL implementation in
// public/assets/js/password-field.js (imported below, never reimplemented
// here): the live complexity checklist and the confirmation check that come
// with partials/password_field.html.twig.
//
// The script decides nothing that matters — Core\Security\PasswordPolicy
// accepts or refuses a password server-side on every one of these forms.
// What is worth testing in isolation is that the mirror says the same thing
// the policy does, and that the "leave blank to keep the current password"
// case really is exempt rather than merely untested.
import { beforeEach, describe, expect, it } from 'vitest';
import {
    evaluate,
    satisfiesPolicy,
    verdict,
    wirePasswordField,
} from '../../public/assets/js/password-field.js';

describe('evaluate', () => {
    it('reports every rule a password fails', () => {
        expect(evaluate('short')).toEqual({
            length: false,
            uppercase: false,
            lowercase: true,
            digit: false,
            symbol: false,
        });
    });

    it('accepts a password satisfying all five', () => {
        expect(evaluate('Abcdefgh1234!')).toEqual({
            length: true,
            uppercase: true,
            lowercase: true,
            digit: true,
            symbol: true,
        });
    });

    it('counts accented letters as letters, in both cases', () => {
        // \p{Lu}/\p{Ll} rather than A-Z: "Élise" starts with a capital, and
        // a French-speaking site that said otherwise would be wrong about
        // its own users' passwords.
        expect(evaluate('Éeeeeeeeeee1!').uppercase).toBe(true);
        expect(evaluate('éEEEEEEEEEE1!').lowercase).toBe(true);
    });

    it('counts a space as a special character', () => {
        expect(evaluate('Abcdefghijk1 ').symbol).toBe(true);
    });

    it('is exactly twelve characters, not eleven', () => {
        expect(satisfiesPolicy('Abcdefgh12!')).toBe(false);
        expect(satisfiesPolicy('Abcdefgh123!')).toBe(true);
    });
});

describe('verdict', () => {
    it('lets a compliant, matching pair through', () => {
        expect(verdict({ value: 'Abcdefgh1234!', confirmation: 'Abcdefgh1234!', optional: false }))
            .toEqual({ ok: true, mismatch: false });
    });

    it('refuses a mismatch, and says which failure it is', () => {
        expect(verdict({ value: 'Abcdefgh1234!', confirmation: 'Abcdefgh1234?', optional: false }))
            .toEqual({ ok: false, mismatch: true });
    });

    it('refuses a non-compliant password before ever looking at the confirmation', () => {
        // Reporting "they do not match" about two identical weak passwords
        // would send somebody looking for the wrong problem.
        expect(verdict({ value: 'weak', confirmation: 'weak', optional: false }))
            .toEqual({ ok: false, mismatch: false });
    });

    it('lets an OPTIONAL field through when it is empty', () => {
        // "Leave blank to keep the current password" — the setup page in
        // update mode.
        expect(verdict({ value: '', confirmation: '', optional: true }))
            .toEqual({ ok: true, mismatch: false });
    });

    it('applies the rules again as soon as an optional field is typed in', () => {
        expect(verdict({ value: 'weak', confirmation: 'weak', optional: true }))
            .toEqual({ ok: false, mismatch: false });
    });

    it('refuses an empty REQUIRED field', () => {
        expect(verdict({ value: '', confirmation: '', optional: false }).ok).toBe(false);
    });

    it('ignores the confirmation when the field has none', () => {
        expect(verdict({ value: 'Abcdefgh1234!', confirmation: null, optional: false }))
            .toEqual({ ok: true, mismatch: false });
    });
});

describe('wirePasswordField', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <form id="f">
                <div data-password-field data-required="1" data-input="pwd" data-confirm="pwd-confirm">
                    <input type="password" id="pwd">
                    <ul id="pwd-checklist">
                        <li class="pwd-rule" data-rule="length"><i class="bi bi-circle"></i></li>
                        <li class="pwd-rule" data-rule="uppercase"><i class="bi bi-circle"></i></li>
                        <li class="pwd-rule" data-rule="lowercase"><i class="bi bi-circle"></i></li>
                        <li class="pwd-rule" data-rule="digit"><i class="bi bi-circle"></i></li>
                        <li class="pwd-rule" data-rule="symbol"><i class="bi bi-circle"></i></li>
                    </ul>
                    <input type="password" id="pwd-confirm">
                    <div data-password-mismatch class="d-none"></div>
                </div>
            </form>`;
    });

    function type(value) {
        const input = document.getElementById('pwd');
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function ruleClasses(rule) {
        return document.querySelector('[data-rule="' + rule + '"]').className;
    }

    it('marks a rule satisfied as it is typed, not on submit', () => {
        wirePasswordField(document.querySelector('[data-password-field]'));

        expect(ruleClasses('length')).toContain('text-body-secondary');

        type('Abcdefgh1234!');

        expect(ruleClasses('length')).toContain('text-success');
        expect(ruleClasses('symbol')).toContain('text-success');
    });

    it('takes a rule back when the password stops satisfying it', () => {
        wirePasswordField(document.querySelector('[data-password-field]'));

        type('Abcdefgh1234!');
        expect(ruleClasses('digit')).toContain('text-success');

        type('Abcdefghijkl!');
        expect(ruleClasses('digit')).toContain('text-body-secondary');
    });

    it('renders once on wiring, so a browser-restored value is not shown as empty', () => {
        document.getElementById('pwd').value = 'Abcdefgh1234!';

        wirePasswordField(document.querySelector('[data-password-field]'));

        expect(ruleClasses('length')).toContain('text-success');
    });

    it('blocks the submit and reveals the message when the two differ', () => {
        wirePasswordField(document.querySelector('[data-password-field]'));
        type('Abcdefgh1234!');
        document.getElementById('pwd-confirm').value = 'Abcdefgh1234?';

        const event = new Event('submit', { bubbles: true, cancelable: true });
        document.getElementById('f').dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(document.querySelector('[data-password-mismatch]').className).not.toContain('d-none');
    });

    it('lets a matching, compliant pair submit', () => {
        wirePasswordField(document.querySelector('[data-password-field]'));
        type('Abcdefgh1234!');
        document.getElementById('pwd-confirm').value = 'Abcdefgh1234!';

        const event = new Event('submit', { bubbles: true, cancelable: true });
        document.getElementById('f').dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('does nothing at all when the input it names is absent', () => {
        document.body.innerHTML = '<div data-password-field data-input="nope"></div>';

        expect(() => wirePasswordField(document.querySelector('[data-password-field]'))).not.toThrow();
    });
});
