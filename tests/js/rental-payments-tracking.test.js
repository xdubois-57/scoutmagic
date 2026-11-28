// Isolated JavaScript unit test — jsdom only, no PHP server. Exercises the
// REAL implementation in public/assets/js/rental-payments-tracking.js: the
// payment-tracking switch saves on change and shows the state stored (#1355).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { settledState, showCardFor, wireTrackingSwitch } from '../../public/assets/js/rental-payments-tracking.js';

describe('settledState', () => {
    it('shows what the server stored on success', () => {
        expect(settledState(true, { status: 200, data: { success: true, enabled: true, message: 'Suivi des paiements activé.' } }))
            .toEqual({ checked: true, ok: true, message: 'Suivi des paiements activé.' });
    });

    it('goes back to the stored state the server reports on a refusal', () => {
        expect(settledState(true, { status: 422, data: { success: false, enabled: false, error: 'Associez un compte.' } }))
            .toEqual({ checked: false, ok: false, message: 'Associez un compte.' });
    });

    it('undoes the click when the server said nothing', () => {
        expect(settledState(true, { status: 0, data: null })).toEqual({ checked: false, ok: false, message: 'Erreur réseau.' });
        expect(settledState(false, { status: 500, data: null }).checked).toBe(true);
    });
});

describe('showCardFor', () => {
    it('shows the parts of the card that belong to the state, and hides the others', () => {
        document.body.innerHTML = '<div class="card">'
            + '<span data-payments-when="on" hidden>suivi</span>'
            + '<span data-payments-when="off">manuel</span>'
            + '<dl data-payments-when="on" hidden>compte</dl></div>';
        const card = /** @type {HTMLElement} */ (document.querySelector('.card'));

        showCardFor(true, card);
        expect(Array.from(card.querySelectorAll('[data-payments-when]')).map((e) => /** @type {HTMLElement} */ (e).hidden))
            .toEqual([false, true, false]);

        showCardFor(false, card);
        expect(Array.from(card.querySelectorAll('[data-payments-when]')).map((e) => /** @type {HTMLElement} */ (e).hidden))
            .toEqual([true, false, true]);
    });
});

describe('wireTrackingSwitch', () => {
    /** @type {any} */
    let toasts;

    beforeEach(() => {
        document.body.innerHTML = '<input type="checkbox" data-payments-tracking="/mes-locations/local/reglages/suivi-paiements">';
        toasts = [];
        window.ScoutMagicToast = { show: (message, options) => toasts.push([message, options.variant]) };
    });

    afterEach(() => {
        delete window.ScoutMagicApi;
        delete window.ScoutMagicToast;
    });

    it('posts the new state alone, then toasts and keeps it', async () => {
        const postJson = vi.fn().mockResolvedValue({ status: 200, data: { success: true, enabled: true, message: 'Suivi des paiements activé.' } });
        window.ScoutMagicApi = { postJson };
        const toggle = /** @type {HTMLInputElement} */ (document.querySelector('input'));
        wireTrackingSwitch(toggle);

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(toasts).toHaveLength(1));

        expect(postJson).toHaveBeenCalledWith('/mes-locations/local/reglages/suivi-paiements', { enabled: true });
        expect(toggle.checked).toBe(true);
        expect(toggle.disabled).toBe(false);
        expect(toasts[0]).toEqual(['Suivi des paiements activé.', 'success']);
    });

    it('updates the card from the state saved, without a reload', async () => {
        document.body.innerHTML = '<div class="card">'
            + '<input type="checkbox" data-payments-tracking="/mes-locations/local/reglages/suivi-paiements">'
            + '<span data-payments-when="on" hidden>suivi</span><span data-payments-when="off">manuel</span></div>';
        window.ScoutMagicApi = { postJson: vi.fn().mockResolvedValue({ status: 200, data: { success: true, enabled: true, message: 'Suivi des paiements activé.' } }) };
        const toggle = /** @type {HTMLInputElement} */ (document.querySelector('input'));
        wireTrackingSwitch(toggle);

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(toasts).toHaveLength(1));

        expect(/** @type {HTMLElement} */ (document.querySelector('[data-payments-when="on"]')).hidden).toBe(false);
        expect(/** @type {HTMLElement} */ (document.querySelector('[data-payments-when="off"]')).hidden).toBe(true);
    });

    it('leaves the card as it was when the server refuses', async () => {
        document.body.innerHTML = '<div class="card">'
            + '<input type="checkbox" data-payments-tracking="/mes-locations/local/reglages/suivi-paiements">'
            + '<span data-payments-when="on" hidden>suivi</span><span data-payments-when="off">manuel</span></div>';
        window.ScoutMagicApi = { postJson: vi.fn().mockResolvedValue({ status: 422, data: { success: false, enabled: false, error: 'Associez un compte.' } }) };
        const toggle = /** @type {HTMLInputElement} */ (document.querySelector('input'));
        wireTrackingSwitch(toggle);

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(toasts).toHaveLength(1));

        expect(/** @type {HTMLElement} */ (document.querySelector('[data-payments-when="on"]')).hidden).toBe(true);
        expect(/** @type {HTMLElement} */ (document.querySelector('[data-payments-when="off"]')).hidden).toBe(false);
    });

    it('returns to the stored state when the server refuses', async () => {
        window.ScoutMagicApi = { postJson: vi.fn().mockResolvedValue({ status: 422, data: { success: false, enabled: false, error: 'Associez un compte.' } }) };
        const toggle = /** @type {HTMLInputElement} */ (document.querySelector('input'));
        wireTrackingSwitch(toggle);

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(toasts).toHaveLength(1));

        expect(toggle.checked).toBe(false);
        expect(toasts[0]).toEqual(['Associez un compte.', 'error']);
    });

    it('keeps aria-checked with the state it puts back, since setting .checked fires no change event', async () => {
        const synced = [];
        window.ScoutMagicNav = { syncSwitchAriaChecked: (input) => synced.push(input.checked) };
        window.ScoutMagicApi = { postJson: vi.fn().mockResolvedValue({ status: 422, data: { success: false, enabled: false, error: 'Associez un compte.' } }) };
        const toggle = /** @type {HTMLInputElement} */ (document.querySelector('input'));
        wireTrackingSwitch(toggle);

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(toasts).toHaveLength(1));

        expect(synced).toEqual([false]);
        delete window.ScoutMagicNav;
    });
});
