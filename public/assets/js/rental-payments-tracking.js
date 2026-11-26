/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « Suivre les paiements via le site » (issue #1355, D11): saved the moment
 * it changes, without reloading, answered with the usual toast — the shape
 * of notification-preferences.js. Nothing else about the money is sent.
 *
 * On a refusal, an error page or a lost network the switch goes back to
 * the state the server reports as STORED, or to what it was before the
 * click when the server said nothing: a switch showing a state that was
 * never saved is the one thing this must not leave behind.
 */

/**
 * The state the switch must show once the server has answered.
 *
 * @param {boolean} requested what the click asked for
 * @param {{status: number, data: any}} res the ScoutMagicApi.postJson envelope
 * @returns {{checked: boolean, ok: boolean, message: string}}
 */
export function settledState(requested, res) {
    const data = res.data ?? null;
    if (data && data.success === true) {
        return {
            checked: data.enabled === true,
            ok: true,
            message: typeof data.message === 'string' ? data.message : 'Enregistré.',
        };
    }

    const stored = data && typeof data.enabled === 'boolean' ? data.enabled : !requested;
    const message = res.status === 0
        ? 'Erreur réseau.'
        : (data && typeof data.error === 'string' ? data.error : "Erreur lors de l'enregistrement.");

    return { checked: stored, ok: false, message };
}

/**
 * Wires one switch.
 *
 * @param {HTMLInputElement} toggle
 * @returns {void}
 */
export function wireTrackingSwitch(toggle) {
    const url = toggle.dataset.paymentsTracking;
    if (!url) {
        return;
    }

    toggle.addEventListener('change', function () {
        const requested = toggle.checked;
        const api = window.ScoutMagicApi;
        if (!api) {
            toggle.checked = !requested;
            return;
        }
        toggle.disabled = true;
        void api.postJson(url, { enabled: requested }).then(function (res) {
            const settled = settledState(requested, res);
            toggle.checked = settled.checked;
            toggle.disabled = false;
            window.ScoutMagicToast?.show(settled.message, { variant: settled.ok ? 'success' : 'error' });
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[data-payments-tracking]').forEach(function (element) {
        wireTrackingSwitch(/** @type {HTMLInputElement} */ (element));
    });
});
