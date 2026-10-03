// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network. Exercises the REAL implementation in
// public/assets/js/form-submit-lock.js (imported below, never
// reimplemented here).
//
// Issue #756: a 4 MB camp photo on a phone takes several seconds to
// leave, and the page used to look exactly as it did before — so the
// visitor pressed Envoyer again and the unit got the photo twice.
//
// The fixture mirrors what `modules/camps/views/photos.html.twig`
// renders: the form, its drop zone's hidden input, the submit button, and
// — beside it — a form that did NOT opt in, because the whole point of
// the data attribute is that adding this file to a page changes nothing
// until a form asks.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <form id="photo-form" method="post" action="/photos" data-submit-lock>
        <input type="file" id="photo-file" name="photo">
        <button type="submit" class="btn btn-primary btn-sm">Envoyer</button>
    </form>
    <form id="invoice-form" method="post" action="/factures" data-submit-lock
          data-submit-lock-label="Lecture en cours…">
        <button type="submit">Lire et vérifier</button>
    </form>
    <form id="plain-form" method="post" action="/autre">
        <input type="file" id="plain-file" name="document">
        <button type="submit">Envoyer</button>
    </form>`;

async function load() {
    vi.resetModules();
    document.body.innerHTML = PAGE;
    // jsdom does not implement form submission and logs "Not implemented"
    // on every submit() — the listener under test runs either way, and
    // what it does to the DOM is the whole observable.
    await import('../../public/assets/js/form-submit-lock.js');
}

/** @param {string} id */
function form(id) {
    return /** @type {HTMLFormElement} */ (document.getElementById(id));
}

/** @param {string} id */
function button(id) {
    return /** @type {HTMLButtonElement} */ (form(id).querySelector('button'));
}

/**
 * Submits the way a click does — a cancelable, bubbling `submit` event —
 * and says whether anything let it through.
 *
 * @param {string} id
 * @returns {boolean} false when a listener prevented it
 */
function submit(id) {
    return form(id).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

describe('form-submit-lock.js', () => {
    beforeEach(async () => {
        delete window.ScoutMagicFormSubmitLock;
    });

    it('disables the button and says what is happening on the first submit', async () => {
        await load();

        submit('photo-form');

        const btn = button('photo-form');
        // Really non-actionable, not merely greyed: a pointer-events
        // trick still submits from the keyboard.
        expect(btn.disabled).toBe(true);
        expect(btn.textContent).toContain('Envoi en cours…');
        // The words carry it. A spinner alone is invisible to a screen
        // reader and reads as decoration to anybody who missed it start.
        expect(btn.querySelector('.spinner-border')).not.toBeNull();
        expect(form('photo-form').getAttribute('aria-busy')).toBe('true');
    });

    it('locks the file input too, so the file cannot change mid-flight', async () => {
        await load();

        submit('photo-form');

        expect(/** @type {HTMLInputElement} */ (document.getElementById('photo-file')).disabled)
            .toBe(true);
    });

    it('lets the first submit through and refuses the second', async () => {
        // The double tap, which is the defect as reported. Disabling the
        // button covers the pointer; this covers Enter in a field and a
        // submit() some other script calls.
        await load();

        expect(submit('photo-form')).toBe(true);
        expect(submit('photo-form')).toBe(false);
    });

    it('uses the label a form asked for', async () => {
        await load();

        submit('invoice-form');

        expect(button('invoice-form').textContent).toContain('Lecture en cours…');
    });

    it('leaves a form that did not opt in completely alone', async () => {
        await load();

        expect(submit('plain-form')).toBe(true);
        expect(submit('plain-form')).toBe(true);
        expect(button('plain-form').disabled).toBe(false);
        expect(button('plain-form').textContent).toBe('Envoyer');
        expect(form('plain-form').hasAttribute('aria-busy')).toBe(false);
    });

    it('does not claim to be sending when another listener refused the submit', async () => {
        // `data-confirm` answered « non », or a validation hook stopped
        // it. Nothing is going anywhere, so nothing should look like it
        // is — and the button has to stay usable for the next attempt.
        await load();
        form('photo-form').addEventListener('submit', (event) => event.preventDefault(), true);

        submit('photo-form');

        expect(button('photo-form').disabled).toBe(false);
        expect(button('photo-form').textContent).toBe('Envoyer');
        expect(form('photo-form').hasAttribute('aria-busy')).toBe(false);
    });

    it('restores the form when the back button brings a locked page into view', async () => {
        // The one way a locked form can be seen again: these forms post
        // and navigate, so success and server refusal both replace the
        // page. Without this, going back shows a button nobody can ever
        // press again — the "jamais bloqué définitivement" of issue #756.
        await load();
        submit('photo-form');

        window.dispatchEvent(new Event('pageshow'));

        const btn = button('photo-form');
        expect(btn.disabled).toBe(false);
        expect(btn.textContent).toBe('Envoyer');
        expect(form('photo-form').hasAttribute('aria-busy')).toBe(false);
        expect(/** @type {HTMLInputElement} */ (document.getElementById('photo-file')).disabled)
            .toBe(false);
        // And it can be sent again, which is what "retry" means here.
        expect(submit('photo-form')).toBe(true);
    });

    it('binds a form added to the page after it loaded, and only once', async () => {
        await load();

        const added = document.createElement('form');
        added.id = 'late-form';
        added.setAttribute('data-submit-lock', '');
        added.innerHTML = '<button type="submit">Envoyer</button>';
        document.body.appendChild(added);

        window.ScoutMagicFormSubmitLock.bind(document);
        window.ScoutMagicFormSubmitLock.bind(document);

        expect(submit('late-form')).toBe(true);
        expect(submit('late-form')).toBe(false);
        expect(button('late-form').disabled).toBe(true);
    });
});
