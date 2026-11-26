// Isolated JavaScript unit test — jsdom only, no PHP server. Exercises the
// REAL implementation in public/assets/js/rental-payments-tracking.js: the
// payment-tracking switch saves on change and shows the state stored (#1355).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { settledState, wireTrackingSwitch } from '../../public/assets/js/rental-payments-tracking.js';

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
});
