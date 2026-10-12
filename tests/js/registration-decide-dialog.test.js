// Isolated JavaScript unit test — jsdom only. Exercises the REAL
// public/assets/js/registration-decide-dialog.js (imported below, never
// reimplemented here) on the markup of the decision dialog of
// Formulaire d'inscription › Demandes (modules/registration/views/config/
// _decide_dialogs.html.twig, issue #843 IT-04).
//
// What is pinned: « Confirmer le refus » may only be pressed with one of the
// two reasons chosen; « Refuser » shows the reason and gives way to it; a
// reason preset by the server (recommendation Non) enables it at once; and
// closing the dialog puts the note, the reason and the buttons back as they
// were opened; and a decision is sent once — a second submit is refused,
// and the buttons are disabled only after the first has been built.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function dialog({ id = 7, note = 'Appeler les parents.', preset = '' } = {}) {
    const option = (value, label) => `<option value="${value}"${preset === value ? ' selected' : ''}>${label}</option>`;
    return `<div class="modal fade" id="decide-${id}">
        <div class="modal-body">
            <form method="post" action="/config/inscriptions/demandes/${id}/decision" id="decide-form-${id}" data-decide-form>
                <textarea name="internal_notes" id="decide-note-${id}">${note}</textarea>
                <div hidden data-decide-reason>
                    <select name="refusal_reason" id="decide-reason-${id}" data-decide-reason-select>
                        ${option('', 'Choisir…')}${option('no_room', 'Pas de place')}${option('other', 'Autre')}
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" data-bs-dismiss="modal">Annuler</button>
            <button type="button" data-decide-refuse>Refuser</button>
            <button type="submit" form="decide-form-${id}" name="decision" value="refuse" hidden disabled data-decide-refuse-confirm>Confirmer le refus</button>
            <button type="submit" form="decide-form-${id}" name="decision" value="accept">Accepter</button>
        </div>
    </div>`;
}

async function boot(markup) {
    vi.resetModules();
    document.body.innerHTML = markup;
    await import('../../public/assets/js/registration-decide-dialog.js');
    return window.ScoutMagicDecideDialog;
}

const $ = (selector) => document.querySelector(selector);
const reasonBlock = () => $('[data-decide-reason]');
const refuse = () => $('[data-decide-refuse]');
const confirmRefusal = () => $('[data-decide-refuse-confirm]');
const select = () => $('[data-decide-reason-select]');

function choose(value) {
    select().value = value;
    select().dispatchEvent(new Event('change'));
}

describe('registration-decide-dialog.js — the reason a refusal needs', () => {
    let api;

    beforeEach(async () => {
        api = await boot(dialog());
    });

    it('allows the refusal only with « Pas de place » or « Autre »', () => {
        expect(api.canConfirmRefusal('no_room')).toBe(true);
        expect(api.canConfirmRefusal('other')).toBe(true);
        expect(api.canConfirmRefusal('')).toBe(false);
        expect(api.canConfirmRefusal('full')).toBe(false);
        expect(api.canConfirmRefusal('No_room')).toBe(false);
    });

    it('opens on « Refuser », with the reason hidden and nothing to confirm', () => {
        expect(reasonBlock().hidden).toBe(true);
        expect(refuse().hidden).toBe(false);
        expect(confirmRefusal().hidden).toBe(true);
        expect(confirmRefusal().disabled).toBe(true);
    });

    it('turns « Refuser » into the reason and a disabled « Confirmer le refus »', () => {
        refuse().click();

        expect(reasonBlock().hidden).toBe(false);
        expect(refuse().hidden).toBe(true);
        expect(confirmRefusal().hidden).toBe(false);
        expect(confirmRefusal().disabled).toBe(true);
        expect(document.activeElement).toBe(select());
    });

    it('enables « Confirmer le refus » once a reason is chosen, and disables it again without one', () => {
        refuse().click();

        choose('other');
        expect(confirmRefusal().disabled).toBe(false);

        choose('');
        expect(confirmRefusal().disabled).toBe(true);

        choose('no_room');
        expect(confirmRefusal().disabled).toBe(false);
    });

    it('puts the dialog back as it was opened when it closes', () => {
        $('textarea').value = 'Une autre note';
        refuse().click();
        choose('other');

        $('.modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect($('textarea').value).toBe('Appeler les parents.');
        expect(select().value).toBe('');
        expect(reasonBlock().hidden).toBe(true);
        expect(refuse().hidden).toBe(false);
        expect(confirmRefusal().hidden).toBe(true);
        expect(confirmRefusal().disabled).toBe(true);
    });
});

describe('registration-decide-dialog.js — a decision is sent once', () => {
    beforeEach(async () => {
        vi.useFakeTimers();
        await boot(dialog());
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    const submit = () => {
        const event = new Event('submit', { cancelable: true });
        $('[data-decide-form]').dispatchEvent(event);
        return event;
    };
    const accept = () => $('button[value="accept"]');

    it('lets the first submit through, refuses the second, and only then disables the buttons', () => {
        expect(submit().defaultPrevented).toBe(false);
        // Still enabled during the submit itself: a disabled submitter
        // would leave its decision out of the request.
        expect(accept().disabled).toBe(false);

        vi.runAllTimers();
        expect(accept().disabled).toBe(true);
        expect(confirmRefusal().disabled).toBe(true);
        expect(submit().defaultPrevented).toBe(true);
    });

    it('can be sent again once the dialog has been closed', () => {
        submit();
        vi.runAllTimers();

        $('.modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(accept().disabled).toBe(false);
        expect(confirmRefusal().disabled).toBe(true);
        expect(submit().defaultPrevented).toBe(false);
    });

    it('is released when the history cache brings the page back', () => {
        submit();
        vi.runAllTimers();

        const restored = new Event('pageshow');
        Object.defineProperty(restored, 'persisted', { value: true });
        window.dispatchEvent(restored);

        expect(accept().disabled).toBe(false);
        expect(submit().defaultPrevented).toBe(false);
    });
});

describe('registration-decide-dialog.js — a reason preset by the recommendation', () => {
    it('confirms at once when the server preset « Pas de place », and keeps it after a close', async () => {
        await boot(dialog({ preset: 'no_room' }));

        refuse().click();
        expect(select().value).toBe('no_room');
        expect(confirmRefusal().disabled).toBe(false);

        choose('');
        $('.modal').dispatchEvent(new Event('hidden.bs.modal'));
        refuse().click();
        expect(select().value).toBe('no_room');
        expect(confirmRefusal().disabled).toBe(false);
    });

    it('binds every decision dialog of the page, and leaves any other modal alone', async () => {
        await boot(dialog({ id: 1 }) + dialog({ id: 2 }) + '<div class="modal" id="other"><button data-decide-refuse>x</button></div>');

        document.querySelector('#decide-2 [data-decide-refuse]').click();

        expect(document.querySelector('#decide-2 [data-decide-reason]').hidden).toBe(false);
        expect(document.querySelector('#decide-1 [data-decide-reason]').hidden).toBe(true);
        expect(document.querySelector('#other [data-decide-refuse]').hidden).toBe(false);
    });
});
