// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so is the site
// dialog (window.ScoutMagicConfirm). Exercises the REAL
// public/assets/js/reenrollment-config.js, imported after the fixture is
// built (the file is an IIFE that reads the DOM at import time).
//
// What matters (issue #796, D2): every save that changes something is
// shown the server's plan first; cancelling saves nothing; agreeing submits
// with the plan's fingerprint — which is what save() checks. A save that
// changes nothing, or a preview that cannot be reached, is submitted as is:
// the server answers « aucun changement » or shows the confirmation itself.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <form method="post" action="/config/reinscription" id="reenrollment-config-form"
          data-preview-endpoint="/config/reinscription/apercu">
        <input type="hidden" name="_csrf_token" value="tok-123">
        <input type="hidden" name="plan_fingerprint" value="">
        <input type="text" name="registration_reenrollment_open_at" value="03-01">
        <input type="text" name="registration_reenrollment_close_at" value="05-15">
        <input type="number" name="registration_reenrollment_reminder_1_days" value="14">
        <input type="number" name="registration_reenrollment_reminder_2_days" value="2">
        <input type="hidden" name="registration_reenrollment_emails_enabled" value="0">
        <input type="checkbox" name="registration_reenrollment_emails_enabled" value="1" checked>
        <input type="checkbox" name="is_open" value="1">
        <button type="submit">Enregistrer</button>
    </form>`;

const DIALOG = {
    title: "Confirmer l'enregistrement",
    campaign: {
        title: 'Ouvre la campagne de réinscription pour 2027-2028',
        detail: 'Fermeture le 15/05/2027, rappels le 01/05/2027 et le 13/05/2027.',
    },
    changes: ['Campagne : fermée → ouverte'],
    mail: {
        headline: '41 e-mails vont partir',
        lines: ["L'e-mail d'ouverture, à toutes les familles (41 familles) — il part dans quelques minutes."],
        note: 'Un e-mail envoyé ne se rappelle pas.',
    },
    none: null,
    confirm_label: 'Enregistrer et envoyer',
    sends: true,
};
const FINGERPRINT = 'a'.repeat(64);

function plan(overrides = {}) {
    return { success: true, changed: true, fingerprint: FINGERPRINT, dialog: DIALOG, ...overrides };
}

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

    it('asks the server for the plan of the form as it is filled in', async () => {
        global.fetch = vi.fn(() => jsonResponse(plan({ changed: false })));
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
        global.fetch = vi.fn(() => jsonResponse(plan({ changed: false })));
        await boot();
        document.querySelector('input[type="checkbox"][name="registration_reenrollment_emails_enabled"]').checked = false;

        submit();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(previewBody().registration_reenrollment_emails_enabled).toBe('0');
    });

    it('submits without asking when nothing changes — the server says so', async () => {
        global.fetch = vi.fn(() => jsonResponse(plan({ changed: false })));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        expect(submitted.mock.calls[0][0].plan_fingerprint).toBe('');
    });

    it('shows the plan — campaign, changes, e-mails — and submits with its fingerprint on yes', async () => {
        global.fetch = vi.fn(() => jsonResponse(plan()));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            title: "Confirmer l'enregistrement",
            message: '',
            content: [
                { kind: 'box', tone: 'muted', title: DIALOG.campaign.title, text: DIALOG.campaign.detail },
                { kind: 'list', title: 'Ce qui change', items: DIALOG.changes },
                {
                    kind: 'box',
                    tone: 'warning',
                    title: '41 e-mails vont partir',
                    items: DIALOG.mail.lines,
                    note: 'Un e-mail envoyé ne se rappelle pas.',
                },
            ],
            confirmLabel: 'Enregistrer et envoyer',
            variant: 'danger',
        });
        expect(submitted.mock.calls[0][0].plan_fingerprint).toBe(FINGERPRINT);
    });

    it('says « Aucun e-mail ne partira » with its reason, on an ordinary button', async () => {
        const quiet = {
            ...DIALOG,
            campaign: null,
            changes: ['Premier rappel : 14 → 10 jours avant la fermeture'],
            mail: null,
            none: { headline: 'Aucun e-mail ne partira.', reason: 'Cet enregistrement ne change que des réglages : rien ne part maintenant.' },
            confirm_label: 'Enregistrer',
            sends: false,
        };
        global.fetch = vi.fn(() => jsonResponse(plan({ dialog: quiet })));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        const asked = window.ScoutMagicConfirm.ask.mock.calls[0][0];
        expect(asked.variant).toBe('primary');
        expect(asked.confirmLabel).toBe('Enregistrer');
        expect(asked.content.at(-1)).toEqual({
            kind: 'box', tone: 'success', title: 'Aucun e-mail ne partira.', text: quiet.none.reason,
        });
    });

    it('saves NOTHING when the chief says no', async () => {
        global.fetch = vi.fn(() => jsonResponse(plan()));
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(false)) };
        await boot();

        submit();

        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(submitted).not.toHaveBeenCalled();
    });

    it('submits unconfirmed when the plan cannot be read — the server then shows the confirmation itself', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();

        submit();

        await vi.waitFor(() => expect(submitted).toHaveBeenCalled());
        expect(submitted.mock.calls[0][0].plan_fingerprint).toBe('');
    });
});
