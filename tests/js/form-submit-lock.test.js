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

    it('says what is happening on the first submit, and locks the button just after', async () => {
        await load();

        submit('photo-form');

        const btn = button('photo-form');
        // NOT yet disabled, and that ordering is load-bearing for the
        // same reason as the file input below: a named submit button
        // disabled during dispatch loses its own field from the body.
        // Measured in Chromium — `action=publier` vanished entirely.
        expect(btn.disabled).toBe(false);
        expect(btn.textContent).toContain('Envoi en cours…');
        // The words carry it. A spinner alone is invisible to a screen
        // reader and reads as decoration to anybody who missed it start.
        expect(btn.querySelector('.spinner-border')).not.toBeNull();
        expect(form('photo-form').getAttribute('aria-busy')).toBe('true');

        await new Promise((resolve) => setTimeout(resolve, 0));

        // Really non-actionable by the next turn, not merely greyed: a
        // pointer-events trick still submits from the keyboard.
        expect(btn.disabled).toBe(true);
    });

    /**
     * The file input is locked, but NOT while the handler runs — and that
     * ordering is the whole of it.
     *
     * The browser builds the multipart body after the `submit` event
     * finishes dispatching, skipping every control disabled at that
     * moment, so disabling it synchronously drops the chosen file from
     * the request. Measured in Chromium: the body carried no `name="photo"`
     * part at all. jsdom implements none of that, so what this test can
     * hold is the ordering that makes it safe — enabled during dispatch,
     * disabled on the next turn.
     */
    /**
     * The named submit button, which is how the bug above would be felt in
     * production: this codebase uses `<button type="submit" name="action"
     * value="…">` to tell one handler which button was pressed, and a
     * server that reads no `action` does nothing at all.
     *
     * None of the four forms wired up here is that shape yet, so it was
     * latent — which is exactly why it needs a test rather than a memory.
     */
    it('keeps a named submit button in the body, then locks it', async () => {
        await load();
        const named = form('photo-form');
        const action = document.createElement('button');
        action.type = 'submit';
        action.name = 'action';
        action.value = 'publier';
        named.appendChild(action);
        window.ScoutMagicFormSubmitLock.bind(document);

        submit('photo-form');

        expect(action.disabled).toBe(false);

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(action.disabled).toBe(true);
    });

    it('leaves the file input enabled during dispatch and locks it just after', async () => {
        await load();
        const input = /** @type {HTMLInputElement} */ (document.getElementById('photo-file'));

        submit('photo-form');

        expect(input.disabled).toBe(false);

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(input.disabled).toBe(true);
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
        // Past the deferred file-input lock, so this really undoes it.
        await new Promise((resolve) => setTimeout(resolve, 0));

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

    /**
     * Two copies of this script on one page, where the one that did NOT
     * lock the form is listening first.
     *
     * The nodes a locked button held live in the WeakMap of the copy that
     * locked it. A copy that does not recognise a form must therefore
     * leave it alone, marker included — otherwise the first `pageshow`
     * listener clears a marker it cannot act on, the owning listener then
     * finds no form left to unlock, and every button stays reading
     * « Envoi en cours… » for the life of the page.
     *
     * The ordering is built deliberately: the first copy is loaded while
     * one document is in place, the document is then replaced, and the
     * second copy binds and locks the new form. The first copy's listener
     * is registered first and owns nothing. This is the same shape as
     * this file's own cross-test pollution, which is how it was found.
     */
    it('leaves a form it did not lock to the copy that did', async () => {
        // Copy one, against a document that is about to be replaced.
        vi.resetModules();
        document.body.innerHTML = '<form id="gone" data-submit-lock><button type="submit">X</button></form>';
        await import('../../public/assets/js/form-submit-lock.js');

        // Copy two, which binds and locks the form that is really there.
        vi.resetModules();
        document.body.innerHTML = PAGE;
        await import('../../public/assets/js/form-submit-lock.js');
        submit('photo-form');
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(button('photo-form').textContent).toContain('Envoi en cours…');

        window.dispatchEvent(new Event('pageshow'));

        const btn = button('photo-form');
        expect(btn.textContent).toBe('Envoyer');
        expect(btn.disabled).toBe(false);
        expect(form('photo-form').hasAttribute('aria-busy')).toBe(false);
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
        // Refused by the LOCKED attribute, which `lock()` sets
        // synchronously — the button's `disabled` lands a turn later and
        // is the visible half of the guard, never the whole of it.
        expect(submit('late-form')).toBe(false);

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(button('late-form').disabled).toBe(true);
    });
});
