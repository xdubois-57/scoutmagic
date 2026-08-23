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
