/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Wires every partials/password_field.html.twig block on the page: the live
// complexity checklist, and the "the two do not match" check on submit.
//
// Declarative on purpose. Each of the three pages that asks for a password
// used to hand-wire this in an inline <script> of its own — which is how the
// setup page ended up with no checklist at all and an eight-character
// minimum where the rest of the site requires twelve. A page now includes
// the partial and this file, and writes nothing.
//
// It decides nothing that matters. Core\Security\PasswordPolicy is what
// accepts or refuses a password, server-side, on every one of these forms.
// What this adds is that somebody finds out while they are typing rather
// than after a round trip.

/**
 * Which of the policy's five rules $password satisfies.
 *
 * The rules are duplicated from PHP deliberately and only here — mirroring
 * Core\Security\PasswordPolicy, which stays the authority. Keeping them in
 * ONE JavaScript file rather than one per page is the whole point.
 *
 * @param {string} password
 * @returns {Record<string, boolean>}
 */
export function evaluate(password) {
    return {
        length: password.length >= 12,
        uppercase: /\p{Lu}/u.test(password),
        lowercase: /\p{Ll}/u.test(password),
        digit: /\d/.test(password),
        symbol: /[^\p{L}\p{N}]/u.test(password),
    };
}

/**
 * @param {string} password
 * @returns {boolean}
 */
export function satisfiesPolicy(password) {
    const results = evaluate(password);

    return Object.keys(results).every(function (rule) { return results[rule]; });
}

/**
 * Whether a block should let its form through.
 *
 * The `optional` case is "leave blank to keep the current password": empty
 * is fine, anything else has to satisfy the rules like everything else.
 *
 * @param {{value: string, confirmation: string|null, optional: boolean}} state
 * @returns {{ok: boolean, mismatch: boolean}}
 */
export function verdict(state) {
    if (state.optional && state.value === '') {
        return { ok: true, mismatch: false };
    }

    if (!satisfiesPolicy(state.value)) {
        return { ok: false, mismatch: false };
    }

    if (state.confirmation !== null && state.confirmation !== state.value) {
        return { ok: false, mismatch: true };
    }

    return { ok: true, mismatch: false };
}

/**
 * @param {HTMLElement} block
 * @returns {void}
 */
export function wirePasswordField(block) {
    const input = /** @type {HTMLInputElement|null} */ (
        document.getElementById(block.dataset.input || '')
    );
    if (!input) {
        return;
    }

    const confirmId = block.dataset.confirm || '';
    const confirmInput = confirmId
        ? /** @type {HTMLInputElement|null} */ (document.getElementById(confirmId))
        : null;
    const mismatch = block.querySelector('[data-password-mismatch]');
    const list = document.getElementById(input.id + '-checklist');
    const optional = block.dataset.required === '0';

    function render() {
        if (!list) {
            return;
        }

        const results = evaluate(input.value);
        Object.keys(results).forEach(function (rule) {
            const item = list.querySelector('[data-rule="' + rule + '"]');
            if (!item) {
                return;
            }

            const ok = results[rule];
            item.classList.toggle('text-success', ok);
            item.classList.toggle('text-body-secondary', !ok);

            const icon = item.querySelector('i');
            if (icon) {
                icon.className = ok
                    ? 'bi bi-check-circle-fill me-1'
                    : 'bi bi-circle text-body-secondary me-1';
            }
        });
    }

    input.addEventListener('input', render);
    render();

    const form = block.closest('form');
    if (!form) {
        return;
    }

    form.addEventListener('submit', function (event) {
        if (mismatch) {
            mismatch.classList.add('d-none');
        }

        const answer = verdict({
            value: input.value,
            confirmation: confirmInput ? confirmInput.value : null,
            optional: optional,
        });

        if (answer.ok) {
            return;
        }

        event.preventDefault();

        if (answer.mismatch && mismatch) {
            mismatch.classList.remove('d-none');
        } else {
            // The rules are right there above the field; put the caret back
            // in it rather than saying the same thing twice.
            input.focus();
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-password-field]').forEach(function (block) {
        wirePasswordField(/** @type {HTMLElement} */ (block));
    });
});
