// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/confirm.js (imported below, never reimplemented): the
// file is an IIFE that installs window.ScoutMagicConfirm at import time.
//
// window.bootstrap is left undefined on purpose. Unlike toast.js, whose
// fallback only changes who runs the hide animation, this file's whole
// point is that it never falls back to the native window.confirm() box —
// so the hand-shown path is the one worth pinning: it must produce the
// same markup, the same focus, and the same answers as the Bootstrap one.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadConfirm() {
    vi.resetModules();
    await import('../../public/assets/js/confirm.js');
    return window.ScoutMagicConfirm;
}

/** @returns {HTMLElement|null} */
function modal() {
    return document.getElementById('sm-confirm-modal');
}

/** @param {string} label @returns {HTMLButtonElement} */
function buttonLabelled(label) {
    const found = [...document.querySelectorAll('#sm-confirm-modal button')]
        .find((b) => b.textContent === label);
    if (!found) {
        throw new Error(`No button labelled "${label}" in the dialog`);
    }
    return /** @type {HTMLButtonElement} */ (found);
}

beforeEach(() => {
    document.body.innerHTML = '';
    delete window.bootstrap;
});

describe('ScoutMagicConfirm.ask()', () => {
    it('never calls the native confirm() it exists to replace', async () => {
        const confirmSpy = vi.fn(() => true);
        window.confirm = confirmSpy;

        const smConfirm = await loadConfirm();
        smConfirm.ask('Supprimer ce reçu ?');

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(modal()).not.toBeNull();
    });

    it('renders the message as text in a labelled, described dialog', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.ask('Supprimer ce reçu ?');

        const root = modal();
        expect(root.getAttribute('aria-labelledby')).toBe('sm-confirm-modal-title');
        expect(root.getAttribute('aria-describedby')).toBe('sm-confirm-modal-body');
        expect(document.getElementById('sm-confirm-modal-title').textContent).toBe('Confirmation');
        expect(document.getElementById('sm-confirm-modal-body').textContent).toBe('Supprimer ce reçu ?');
    });

    it('never interprets the message as markup', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.ask('<img src=x onerror=alert(1)>');

        expect(document.querySelector('#sm-confirm-modal img')).toBeNull();
        expect(document.getElementById('sm-confirm-modal-body').textContent).toContain('<img');
    });

    it('styles the confirmation as destructive by default', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.ask('Supprimer ce reçu ?');

        expect(buttonLabelled('Confirmer').className).toContain('btn-danger');
        expect(buttonLabelled('Annuler').className).toContain('btn-outline-secondary');
    });

    it('accepts a primary variant and custom labels for a non-destructive ask', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.ask({
            message: 'Appliquer les règles à tous les mouvements ?',
            title: 'Catégorisation',
            confirmLabel: 'Appliquer',
            variant: 'primary',
        });

        expect(document.getElementById('sm-confirm-modal-title').textContent).toBe('Catégorisation');
        expect(buttonLabelled('Appliquer').className).toContain('btn-primary');
        expect(buttonLabelled('Appliquer').className).not.toContain('btn-danger');
    });

    it('puts focus on Annuler, never on the destructive button', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.ask('Supprimer définitivement cet album ?');

        expect(document.activeElement).toBe(buttonLabelled('Annuler'));
    });

    it('resolves true only when the confirmation button is pressed', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        buttonLabelled('Confirmer').click();

        await expect(answer).resolves.toBe(true);
        expect(modal()).toBeNull();
    });

    it('resolves false on Annuler', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        buttonLabelled('Annuler').click();

        await expect(answer).resolves.toBe(false);
        expect(modal()).toBeNull();
    });

    it('resolves false on the close button', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        /** @type {HTMLButtonElement} */ (document.querySelector('#sm-confirm-modal .btn-close')).click();

        await expect(answer).resolves.toBe(false);
    });

    it('resolves false on Escape', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));

        await expect(answer).resolves.toBe(false);
        expect(modal()).toBeNull();
    });

    it('resolves false when the backdrop is clicked', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        modal().dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        await expect(answer).resolves.toBe(false);
    });

    it('answers exactly once — a late Escape cannot flip a confirmed answer', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        buttonLabelled('Confirmer').click();
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));

        await expect(answer).resolves.toBe(true);
    });

    it('stops listening for Escape once the dialog is answered', async () => {
        const smConfirm = await loadConfirm();
        const first = smConfirm.ask('Supprimer ce reçu ?');
        buttonLabelled('Annuler').click();
        await first;

        // A stray Escape on a page with no dialog must not throw.
        expect(() => document.dispatchEvent(
            new window.KeyboardEvent('keydown', { key: 'Escape' }),
        )).not.toThrow();
    });

    it('hands the id to the new dialog the instant the old one is answered', async () => {
        // Bootstrap removes an element only when its hide transition ends,
        // so without this the document briefly holds two #sm-confirm-modal
        // and every selector finds the dying one first — including
        // Playwright's, which errors on an ambiguous locator.
        const hide = vi.fn();
        window.bootstrap = { Modal: vi.fn(function () { return { show: vi.fn(), hide }; }) };

        const smConfirm = await loadConfirm();
        smConfirm.ask('Première question ?');
        smConfirm.ask('Deuxième question ?');

        // The first is still in the document — Bootstrap has not finished
        // hiding it — but it no longer answers to the id.
        expect(document.querySelectorAll('#sm-confirm-modal').length).toBe(1);
        expect(document.getElementById('sm-confirm-modal-body').textContent).toBe('Deuxième question ?');
    });

    it('answers a still-open dialog with false rather than stacking a second', async () => {
        const smConfirm = await loadConfirm();
        const first = smConfirm.ask('Première question ?');
        const second = smConfirm.ask('Deuxième question ?');

        await expect(first).resolves.toBe(false);
        expect(document.querySelectorAll('#sm-confirm-modal').length).toBe(1);
        expect(document.getElementById('sm-confirm-modal-body').textContent).toBe('Deuxième question ?');

        buttonLabelled('Confirmer').click();
        await expect(second).resolves.toBe(true);
    });

    it('leaves no dialog or backdrop behind once answered', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');
        expect(document.querySelector('.modal-backdrop')).not.toBeNull();

        buttonLabelled('Annuler').click();
        await answer;

        expect(document.querySelector('.modal-backdrop')).toBeNull();
        expect(modal()).toBeNull();
    });

    it('shares its dialog with prompt() — the second ask dismisses a pending prompt', async () => {
        const smConfirm = await loadConfirm();
        const typed = smConfirm.prompt('URL du lien :');
        const asked = smConfirm.ask('Supprimer ce reçu ?');

        await expect(typed).resolves.toBeNull();
        expect(document.querySelectorAll('#sm-confirm-modal').length).toBe(1);
        expect(document.getElementById('sm-confirm-modal-input')).toBeNull();

        buttonLabelled('Annuler').click();
        await expect(asked).resolves.toBe(false);
    });

    it('drives the Bootstrap dialog when the bundle is present', async () => {
        const show = vi.fn();
        const hide = vi.fn();
        window.bootstrap = { Modal: vi.fn(function () { return { show, hide }; }) };

        const smConfirm = await loadConfirm();
        const answer = smConfirm.ask('Supprimer ce reçu ?');

        expect(show).toHaveBeenCalledTimes(1);
        // Bootstrap owns the backdrop; the hand-shown one must not appear.
        expect(document.querySelector('.modal-backdrop')).toBeNull();

        buttonLabelled('Confirmer').click();
        await expect(answer).resolves.toBe(true);
        expect(hide).toHaveBeenCalledTimes(1);
    });
});

describe('ScoutMagicConfirm.prompt()', () => {
    /** @returns {HTMLInputElement} */
    function field() {
        const el = document.getElementById('sm-confirm-modal-input');
        if (el === null) {
            throw new Error('The prompt dialog has no input field');
        }
        return /** @type {HTMLInputElement} */ (el);
    }

    it('never calls the native prompt() it exists to replace', async () => {
        const promptSpy = vi.fn(() => 'https://example.org');
        window.prompt = promptSpy;

        const smConfirm = await loadConfirm();
        smConfirm.prompt('URL du lien :');

        expect(promptSpy).not.toHaveBeenCalled();
        expect(modal()).not.toBeNull();
    });

    it('shows one text field labelled by the question', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.prompt('URL du lien :');

        expect(document.getElementById('sm-confirm-modal-body').textContent).toBe('URL du lien :');
        expect(field().type).toBe('text');
        expect(field().getAttribute('aria-labelledby')).toBe('sm-confirm-modal-body');
    });

    it('is styled as the harmless action it is, not as a destructive one', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.prompt('URL du lien :');

        expect(buttonLabelled('Valider').className).toContain('btn-primary');
        expect(document.querySelector('#sm-confirm-modal .btn-danger')).toBeNull();
    });

    it('puts focus in the field, since there is something to type', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.prompt('URL du lien :');

        expect(document.activeElement).toBe(field());
    });

    it('resolves to what was typed', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.prompt('URL du lien :');
        field().value = 'https://lesscouts.be';
        buttonLabelled('Valider').click();

        await expect(answer).resolves.toBe('https://lesscouts.be');
    });

    it('accepts Enter in the field as validation', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.prompt('URL du lien :');
        field().value = 'https://lesscouts.be';
        field().dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        await expect(answer).resolves.toBe('https://lesscouts.be');
    });

    it('resolves null — never an empty string — when dismissed', async () => {
        const smConfirm = await loadConfirm();

        const cancelled = smConfirm.prompt('URL du lien :');
        buttonLabelled('Annuler').click();
        await expect(cancelled).resolves.toBeNull();

        const escaped = smConfirm.prompt('URL du lien :');
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        await expect(escaped).resolves.toBeNull();
    });

    it('distinguishes an empty answer from a dismissal', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.prompt('URL du lien :');
        buttonLabelled('Valider').click();

        // '' means "the visitor cleared the field and confirmed"; null
        // means "there is no answer". A caller that clears a link needs
        // to tell those apart.
        await expect(answer).resolves.toBe('');
    });

    it('pre-selects a supplied value so it can be replaced or copied', async () => {
        const smConfirm = await loadConfirm();
        const select = vi.fn();
        // jsdom implements select(); spy on the instance the dialog builds.
        const original = window.HTMLInputElement.prototype.select;
        window.HTMLInputElement.prototype.select = select;
        try {
            smConfirm.prompt({
                message: 'Copiez le lien de ce message :',
                value: 'https://lesscouts.be/p/42',
                readonly: true,
                confirmLabel: 'Fermer',
            });
        } finally {
            window.HTMLInputElement.prototype.select = original;
        }

        expect(field().value).toBe('https://lesscouts.be/p/42');
        expect(field().readOnly).toBe(true);
        expect(select).toHaveBeenCalled();
        expect(buttonLabelled('Fermer')).toBeTruthy();
    });

    it('leaves an empty field alone rather than selecting nothing', async () => {
        const smConfirm = await loadConfirm();
        const select = vi.fn();
        const original = window.HTMLInputElement.prototype.select;
        window.HTMLInputElement.prototype.select = select;
        try {
            smConfirm.prompt({ message: 'URL du lien :', placeholder: 'https://…' });
        } finally {
            window.HTMLInputElement.prototype.select = original;
        }

        expect(select).not.toHaveBeenCalled();
        expect(field().placeholder).toBe('https://…');
    });
});

describe('ScoutMagicConfirm.prompt() with a note', () => {
    /** @returns {HTMLTextAreaElement} */
    function textarea() {
        const el = document.getElementById('sm-confirm-modal-input');
        if (el === null) {
            throw new Error('The prompt dialog has no field');
        }
        return /** @type {HTMLTextAreaElement} */ (el);
    }

    it('renders a textarea with its own label when the answer is a few sentences', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.prompt({
            message: 'Refuser cette demande de réservation ?',
            label: 'Un mot au locataire (facultatif)',
            multiline: true,
        });

        expect(textarea().tagName).toBe('TEXTAREA');
        expect(textarea().rows).toBe(3);
        // A second sentence exists, so it is the field's label — not the
        // decision, which stays the question in the body.
        const label = document.querySelector('#sm-confirm-modal label');
        expect(label.textContent).toBe('Un mot au locataire (facultatif)');
        expect(label.getAttribute('for')).toBe('sm-confirm-modal-input');
        expect(textarea().getAttribute('aria-labelledby')).toBeNull();
        expect(document.getElementById('sm-confirm-modal-body').textContent)
            .toBe('Refuser cette demande de réservation ?');
    });

    it('lets Enter start a new line instead of agreeing', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.prompt({ message: 'Refuser ?', label: 'Un mot', multiline: true });

        textarea().value = 'Première ligne';
        textarea().dispatchEvent(
            new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
        );

        // The dialog is still open: nothing was sent.
        expect(modal()).not.toBeNull();
        textarea().value = 'Première ligne\nSeconde ligne';
        buttonLabelled('Valider').click();
        await expect(answer).resolves.toBe('Première ligne\nSeconde ligne');
    });

    it('still submits on Enter in a one-line field', async () => {
        const smConfirm = await loadConfirm();
        const answer = smConfirm.prompt('URL du lien :');

        const el = /** @type {HTMLInputElement} */ (document.getElementById('sm-confirm-modal-input'));
        expect(el.tagName).toBe('INPUT');
        el.value = 'https://lesscouts.be';
        el.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));

        await expect(answer).resolves.toBe('https://lesscouts.be');
    });

    it('styles the dialog as destructive when the caller says the action is', async () => {
        const smConfirm = await loadConfirm();
        smConfirm.prompt({ message: 'Refuser ?', label: 'Un mot', multiline: true, variant: 'danger' });

        expect(buttonLabelled('Valider').className).toContain('btn-danger');
    });
});

// The other half of this file: the handler that makes data-confirm work at
// all. It used to be an inline <script> in base.html.twig, where no unit
// test could reach it — and before that, an identical copy in three page
// templates, missing from a fourth.
describe('the delegated data-confirm form handler', () => {
    /**
     * @param {Record<string, string>} attrs
     * @returns {HTMLFormElement}
     */
    function formWith(attrs) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/mes-locations/statut';
        for (const [name, value] of Object.entries(attrs)) {
            form.setAttribute(name, value);
        }
        const button = document.createElement('button');
        button.type = 'submit';
        button.textContent = 'Refuser';
        form.appendChild(button);
        document.body.appendChild(form);
        return form;
    }

    /** @param {HTMLFormElement} form */
    function submit(form) {
        // jsdom would try to navigate; the handler replays through
        // requestSubmit(), so that is what a replay looks like here.
        const replay = vi.fn();
        form.requestSubmit = replay;
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        return replay;
    }

    it('asks before submitting, and replays the form once confirmed', async () => {
        await loadConfirm();
        const form = formWith({ 'data-confirm': 'Annuler cette réservation ?' });

        const replay = submit(form);
        expect(replay).not.toHaveBeenCalled();
        expect(document.getElementById('sm-confirm-modal-body').textContent)
            .toBe('Annuler cette réservation ?');

        buttonLabelled('Confirmer').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(replay).toHaveBeenCalled();
        // The replayed submit must not ask a second time.
        expect(form.dataset.confirmed).toBe('1');
    });

    it('leaves the form alone when the visitor declines', async () => {
        await loadConfirm();
        const form = formWith({ 'data-confirm': 'Annuler cette réservation ?' });

        const replay = submit(form);
        buttonLabelled('Annuler').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(replay).not.toHaveBeenCalled();
        expect(form.dataset.confirmed).toBeUndefined();
    });

    it('lets the replayed submit through instead of asking again', async () => {
        await loadConfirm();
        const form = formWith({ 'data-confirm': 'Annuler ?' });
        form.dataset.confirmed = '1';

        const event = new window.Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(modal()).toBeNull();
    });

    it('honours a custom label on the agreeing button', async () => {
        await loadConfirm();
        submit(formWith({ 'data-confirm': 'Refuser ?', 'data-confirm-label': 'Refuser' }));

        expect(buttonLabelled('Refuser')).toBeTruthy();
    });

    it('works for a form inserted after load, which is why it is delegated', async () => {
        await loadConfirm();
        // Inserted here, long after the listener was attached — a
        // querySelectorAll() at load time could never have seen it.
        const replay = submit(formWith({ 'data-confirm': 'Supprimer ce message ?' }));

        buttonLabelled('Confirmer').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(replay).toHaveBeenCalled();
    });

    describe('with data-confirm-note', () => {
        it('asks for a word and posts it under the given name', async () => {
            await loadConfirm();
            const form = formWith({
                'data-confirm': 'Refuser cette demande ?',
                'data-confirm-note': 'Un mot au locataire (facultatif)',
                'data-confirm-note-name': 'renter_message',
            });

            const replay = submit(form);
            const written = /** @type {HTMLTextAreaElement} */ (
                document.getElementById('sm-confirm-modal-input')
            );
            expect(written.tagName).toBe('TEXTAREA');
            written.value = 'Le gîte est déjà pris ce week-end.';
            buttonLabelled('Confirmer').click();
            await Promise.resolve();
            await Promise.resolve();

            const carrier = /** @type {HTMLInputElement} */ (
                form.querySelector('input[type="hidden"][data-confirm-note-field]')
            );
            expect(carrier.name).toBe('renter_message');
            expect(carrier.value).toBe('Le gîte est déjà pris ce week-end.');
            expect(replay).toHaveBeenCalled();
        });

        it('defaults the field name to message', async () => {
            await loadConfirm();
            const form = formWith({ 'data-confirm': 'Refuser ?', 'data-confirm-note': 'Un mot' });

            submit(form);
            buttonLabelled('Confirmer').click();
            await Promise.resolve();
            await Promise.resolve();

            expect(form.querySelector('input[data-confirm-note-field]').getAttribute('name'))
                .toBe('message');
        });

        it('submits with no word at all, because the word is optional', async () => {
            await loadConfirm();
            const form = formWith({ 'data-confirm': 'Refuser ?', 'data-confirm-note': 'Un mot' });

            const replay = submit(form);
            buttonLabelled('Confirmer').click();
            await Promise.resolve();
            await Promise.resolve();

            expect(replay).toHaveBeenCalled();
            expect(form.querySelector('input[data-confirm-note-field]').getAttribute('value'))
                .toBe('');
        });

        it('cancels on a dismissal without posting anything', async () => {
            await loadConfirm();
            const form = formWith({ 'data-confirm': 'Refuser ?', 'data-confirm-note': 'Un mot' });

            const replay = submit(form);
            document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
            await Promise.resolve();
            await Promise.resolve();

            expect(replay).not.toHaveBeenCalled();
            expect(form.querySelector('input[data-confirm-note-field]')).toBeNull();
        });

        it('carries one word, not the one before it, when the visitor reopens', async () => {
            await loadConfirm();
            const form = formWith({ 'data-confirm': 'Refuser ?', 'data-confirm-note': 'Un mot' });

            submit(form);
            /** @type {HTMLTextAreaElement} */ (
                document.getElementById('sm-confirm-modal-input')
            ).value = 'Premier essai';
            buttonLabelled('Confirmer').click();
            await Promise.resolve();
            await Promise.resolve();

            // A second decision on the same form — the replay guard is
            // cleared the way a fresh page load would.
            delete form.dataset.confirmed;
            submit(form);
            /** @type {HTMLTextAreaElement} */ (
                document.getElementById('sm-confirm-modal-input')
            ).value = 'Second essai';
            buttonLabelled('Confirmer').click();
            await Promise.resolve();
            await Promise.resolve();

            const carriers = form.querySelectorAll('input[data-confirm-note-field]');
            expect(carriers).toHaveLength(1);
            expect(/** @type {HTMLInputElement} */ (carriers[0]).value).toBe('Second essai');
        });
    });
});
