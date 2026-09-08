/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

/**
 * #251. The public form's submit button stayed live for the whole round
 * trip: a second tap on a phone sent the whole form again — a second
 * response, and on a paying form a second receivable and a second ticket.
 *
 * The real file is imported, never re-implemented: it registers one
 * delegated listener on `document` at import time.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

async function loadScript() {
    vi.resetModules();
    await import('../../public/assets/js/submit-once.js');
}

describe('submit-once', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        vi.useFakeTimers();
    });

    it('disables the submit button of a form that asks for it', async () => {
        document.body.innerHTML =
            '<form data-submit-once><button type="submit" id="go">Envoyer</button></form>';
        await loadScript();

        const form = document.querySelector('form');
        const button = /** @type {HTMLButtonElement} */ (document.getElementById('go'));
        expect(button.disabled).toBe(false);

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        vi.runAllTimers();

        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
    });

    it('leaves an ordinary form alone', async () => {
        document.body.innerHTML = '<form><button type="submit" id="go">Envoyer</button></form>';
        await loadScript();

        document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        vi.runAllTimers();

        expect(/** @type {HTMLButtonElement} */ (document.getElementById('go')).disabled).toBe(false);
    });

    it('stands aside while another listener has already stopped the submission', async () => {
        // confirm.js prevents the first submit and replays it once the
        // visitor agrees; disabling the button in between would leave a
        // form nobody can send.
        document.body.innerHTML =
            '<form data-submit-once><button type="submit" id="go">Envoyer</button></form>';
        await loadScript();

        const form = document.querySelector('form');
        const event = new Event('submit', { bubbles: true, cancelable: true });
        event.preventDefault();
        form.dispatchEvent(event);
        vi.runAllTimers();

        expect(/** @type {HTMLButtonElement} */ (document.getElementById('go')).disabled).toBe(false);
    });
});
