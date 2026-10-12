// Isolated JavaScript unit test — jsdom only. Exercises the REAL
// public/assets/js/registration-automation-settings.js (imported below,
// never reimplemented here) on the markup of the sub-box « Décisions
// automatiques » of Formulaire d'inscription › Réglages
// (modules/registration/views/config/_settings_automation.html.twig, issue
// #843 IT-10).
//
// What is pinned: « Accepter automatiquement les demandes « Oui » » is
// usable only while waitlists are managed, the automatic sending is ticked
// and the acceptance template is written — the server's order —, is
// unchecked and disabled the moment one goes, shows the help text naming
// the missing one, and follows « Gérer les listes d'attente » through the
// event the capacity script dispatches once saved.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function box({ waitlist = true, send = true, template = true, accept = false } = {}) {
    const missing = !waitlist ? 'waitlist' : !send ? 'auto_send' : !template ? 'template' : null;
    const help = (key) => `<span data-auto-accept-help="${key}" ${(missing ?? 'ready') === key ? '' : 'hidden'}>${key}</span>`;

    return `<form method="post" action="/config/inscriptions/decisions-automatiques" data-decision-automation
              data-waitlist-enabled="${waitlist ? '1' : '0'}" data-acceptance-template-ready="${template ? '1' : '0'}">
        <input type="checkbox" id="registration-auto-send" name="auto_send" value="1"
               data-auto-send-switch ${send ? 'checked' : ''}>
        <input type="checkbox" id="registration-auto-accept" name="auto_accept" value="1"
               data-auto-accept-switch ${accept && missing === null ? 'checked' : ''} ${missing === null ? '' : 'disabled'}>
        <span id="registration-auto-accept-help">
            ${help('waitlist')}${help('auto_send')}${help('template')}${help('ready')}
        </span>
        <button type="submit">Enregistrer</button>
    </form>`;
}

async function load(markup) {
    document.body.innerHTML = markup;
    vi.resetModules();
    await import('../../public/assets/js/registration-automation-settings.js');

    return {
        send: /** @type {HTMLInputElement} */ (document.getElementById('registration-auto-send')),
        accept: /** @type {HTMLInputElement} */ (document.getElementById('registration-auto-accept')),
    };
}

/** The key of the one help text shown. */
function shownHelp() {
    const shown = [...document.querySelectorAll('[data-auto-accept-help]')].filter((el) => !el.hidden);
    expect(shown).toHaveLength(1);

    return shown[0].getAttribute('data-auto-accept-help');
}

function waitlistSaved(enabled) {
    document.dispatchEvent(new CustomEvent('registration:waitlist-changed', { detail: { enabled } }));
}

describe('registration-automation-settings.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('names the first missing prerequisite, in the server\'s order', async () => {
        await load(box());
        const { missingPrerequisite } = window.ScoutMagicRegistrationAutomationSettings;

        expect(missingPrerequisite(true, true, true)).toBeNull();
        expect(missingPrerequisite(false, false, false)).toBe('waitlist');
        expect(missingPrerequisite(true, false, false)).toBe('auto_send');
        expect(missingPrerequisite(true, true, false)).toBe('template');
    });

    it('leaves a switched-on acceptance as rendered when nothing is missing', async () => {
        const { accept } = await load(box({ accept: true }));

        expect(accept.disabled).toBe(false);
        expect(accept.checked).toBe(true);
        expect(shownHelp()).toBe('ready');
    });

    it('unchecks and disables it when the automatic sending is unticked, and frees it again', async () => {
        const { send, accept } = await load(box({ accept: true }));

        send.checked = false;
        send.dispatchEvent(new Event('change'));
        expect(accept.disabled).toBe(true);
        expect(accept.checked).toBe(false);
        expect(shownHelp()).toBe('auto_send');

        send.checked = true;
        send.dispatchEvent(new Event('change'));
        expect(accept.disabled).toBe(false);
        expect(accept.checked).toBe(false);
        expect(shownHelp()).toBe('ready');
    });

    it('frees it when the automatic sending is ticked on a page rendered without it', async () => {
        const { send, accept } = await load(box({ send: false }));
        expect(accept.disabled).toBe(true);

        send.checked = true;
        send.dispatchEvent(new Event('change'));

        expect(accept.disabled).toBe(false);
        expect(shownHelp()).toBe('ready');
    });

    it('stays unavailable while the acceptance template is not written', async () => {
        const { send, accept } = await load(box({ template: false }));

        send.dispatchEvent(new Event('change'));

        expect(accept.disabled).toBe(true);
        expect(shownHelp()).toBe('template');
    });

    it('follows « Gérer les listes d\'attente » once the server saved it', async () => {
        const { accept } = await load(box({ accept: true }));

        waitlistSaved(false);
        expect(accept.disabled).toBe(true);
        expect(accept.checked).toBe(false);
        expect(shownHelp()).toBe('waitlist');

        waitlistSaved(true);
        expect(accept.disabled).toBe(false);
        expect(shownHelp()).toBe('ready');
    });
});
