// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so is the site
// dialog (window.ScoutMagicConfirm). Exercises the REAL
// public/assets/js/reenrollment-config.js, imported after the fixture is
// built (the file is an IIFE that reads the DOM at import time).
//
// What matters (issue #732): a save the server says would open the
// campaign and write to every family is asked first, cancelling saves
// nothing, and agreeing submits with `confirm_opening` — which is what
// save() checks.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <form method="post" action="/config/reinscription" id="reenrollment-config-form"
          data-preview-endpoint="/config/reinscription/apercu">
        <input type="hidden" name="_csrf_token" value="tok-123">
        <input type="hidden" name="confirm_opening" value="">
        <input type="text" name="registration_reenrollment_open_at" value="03-01">
        <input type="text" name="registration_reenrollment_close_at" value="05-15">
        <input type="number" name="registration_reenrollment_reminder_1_days" value="14">
        <input type="number" name="registration_reenrollment_reminder_2_days" value="2">
        <input type="hidden" name="registration_reenrollment_emails_enabled" value="0">
        <input type="checkbox" name="registration_reenrollment_emails_enabled" value="1" checked>
        <input type="checkbox" name="is_open" value="1">
        <button type="submit">Enregistrer</button>
    </form>`;

const QUESTION = "Cette configuration va ouvrir la campagne de réinscription immédiatement. "
    + "Un e-mail d'ouverture sera envoyé aux familles concernées. Voulez-vous continuer ?";

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('reenrollment-config.js', () => {
    let submitted;

    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        submitted = vi.fn();
        // jsdom implements no navigation: the real submit is observed.
        HTMLFormElement.prototype.requestSubmit = function () {
            submitted(Object.fromEntries(new FormData(this)));
        };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/reenrollment-config.js');
    }

    const form = () => document.getElementById('reenrollment-config-form');
    const submit = () => form().dispatchEvent(new Event('submit', { cancelable: true }));
    const previewBody = () => JSON.parse(fetch.mock.calls[0][1].body);

    it('does nothing on a page without the form', async () => {
        document.body.innerHTML = '<p>Autre page</p>';
        global.fetch = vi.fn();
        await expect(boot()).resolves.not.toThrow();
    });

    it('asks the server what the save would set off, with the form as it is filled in', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, confirm: null }));
        await boot();
        document.querySelector('input[name="is_open"]').checked = true;

        submit();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(fetch.mock.calls[0][0]).toBe('/config/reinscription/apercu');
        expect(previewBody()).toMatchObject({
            registration_reenrollment_open_at: '03-01',
            registration_reenrollment_close_at: '05-15',
            registration_reenrollment_emails_enabled: '1',
            is_open: '1',
            _csrf_token: 'tok-123',
        });
    });

    it('reads a switched-off e-mails switch as « 0 »', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, confirm: null }));
        await boot();
        document.querySelector('input[type="checkbox"][name="registration_reenrollment_emails_enabled"]').checked = false;

        submit();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(previewBody().registration_reenrollment_emails_enabled).toBe('0');
    });

    it('submits at once when there is nothing to ask', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, confirm: null }));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        expect(submitted.mock.calls[0][0].confirm_opening).toBe('');
    });

    it('asks first when the save would open the campaign and write to families, then submits with the yes', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, confirm: QUESTION }));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: QUESTION,
            confirmLabel: 'Ouvrir et envoyer',
        });
        expect(submitted.mock.calls[0][0].confirm_opening).toBe('1');
    });

    it('saves NOTHING when the chief says no', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, confirm: QUESTION }));
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(false)) };
        await boot();

        submit();

        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(submitted).not.toHaveBeenCalled();
    });

    it('still submits when the question cannot be asked — the server guards the save itself', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        expect(submitted.mock.calls[0][0].confirm_opening).toBe('');
    });
});
