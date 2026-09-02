// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP
// server, no network. Exercises the REAL implementation in
// public/assets/js/news-event-details.js (imported below, never
// reimplemented here).
//
// The file is an IIFE that runs at import time, so each test builds its
// DOM first and then imports the module via vi.resetModules() + await
// import() (the tests/js/nav-rail.test.js pattern).
//
// What is under test is one decision and one consequence: whether saving
// the article editor needs the ICS warning, and that the save is never
// blocked by it.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function buildEditor({ originalDate = '', originalLocation = '', icsAlreadySent = '0', date = '', location = '' } = {}) {
    document.body.innerHTML = `
        <form id="news-editor-form"
              data-original-event-date="${originalDate}"
              data-original-event-location="${originalLocation}"
              data-ics-already-sent="${icsAlreadySent}">
            <input type="date" id="form_event_date" value="${date}">
            <input type="text" id="form_event_location" value="${location}">
            <button type="submit">Enregistrer</button>
        </form>
    `;

    const form = /** @type {HTMLFormElement} */ (document.getElementById('news-editor-form'));
    // jsdom implements neither form submission nor requestSubmit.
    form.requestSubmit = vi.fn();

    return form;
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/news-event-details.js');
}

/** @returns {(state: object) => boolean} */
function decide() {
    // @ts-ignore — the global the file exposes for exactly this.
    return window.ScoutMagicNewsEventDetails.needsIcsWarning;
}

describe('the ICS warning decision', () => {
    beforeEach(async () => {
        buildEditor();
        await load();
    });

    it('says nothing when no ICS has ever gone out', () => {
        // Nothing in anybody's calendar means nothing to contradict —
        // a brand new form, or one that never carried a date.
        expect(decide()({
            originalDate: '', originalLocation: '', date: '2026-03-14', location: 'Salle', icsAlreadySent: false,
        })).toBe(false);
    });

    it('says nothing when neither value changed', () => {
        // Opening the editor to rename a field must not ask about a date
        // nobody touched.
        expect(decide()({
            originalDate: '2026-03-14', originalLocation: 'Salle', date: '2026-03-14', location: 'Salle', icsAlreadySent: true,
        })).toBe(false);
    });

    it('warns when the date changes', () => {
        expect(decide()({
            originalDate: '2026-03-14', originalLocation: 'Salle', date: '2026-03-21', location: 'Salle', icsAlreadySent: true,
        })).toBe(true);
    });

    it('warns when only the place changes', () => {
        // The address is in the calendar entry too, and it is what
        // somebody drives to.
        expect(decide()({
            originalDate: '2026-03-14', originalLocation: 'Salle', date: '2026-03-14', location: 'Chapelle', icsAlreadySent: true,
        })).toBe(true);
    });

    it('warns when a date is cleared', () => {
        expect(decide()({
            originalDate: '2026-03-14', originalLocation: '', date: '', location: '', icsAlreadySent: true,
        })).toBe(true);
    });
});

describe('the editor form', () => {
    it('submits untouched when nothing needs warning', async () => {
        const form = buildEditor({ originalDate: '2026-03-14', icsAlreadySent: '1', date: '2026-03-14' });
        await load();

        const event = new Event('submit', { cancelable: true, bubbles: true });
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('holds the save back and asks when a value changed', async () => {
        const form = buildEditor({ originalDate: '2026-03-14', icsAlreadySent: '1', date: '2026-03-21' });
        const ask = vi.fn().mockResolvedValue(true);
        // @ts-ignore — the site's one confirmation dialog (confirm.js).
        window.ScoutMagicConfirm = { ask, prompt: vi.fn() };
        await load();

        const event = new Event('submit', { cancelable: true, bubbles: true });
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(ask).toHaveBeenCalledTimes(1);
        // Nothing is destroyed and nothing is refused — a date changes for
        // good reasons, so this is not the danger styling.
        expect(ask.mock.calls[0][0].variant).toBe('primary');

        await Promise.resolve();
        await Promise.resolve();
        expect(form.requestSubmit).toHaveBeenCalledTimes(1);
    });

    it('does not save when the author backs out', async () => {
        const form = buildEditor({ originalDate: '2026-03-14', icsAlreadySent: '1', date: '2026-03-21' });
        // @ts-ignore
        window.ScoutMagicConfirm = { ask: vi.fn().mockResolvedValue(false), prompt: vi.fn() };
        await load();

        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        await Promise.resolve();
        await Promise.resolve();
        expect(form.requestSubmit).not.toHaveBeenCalled();
    });

    it('lets the save through when no dialog is available', async () => {
        // An offline page, a script that failed to load: the warning is a
        // courtesy, never a gate, and an author must not be trapped on a
        // form that will not submit.
        const form = buildEditor({ originalDate: '2026-03-14', icsAlreadySent: '1', date: '2026-03-21' });
        // @ts-ignore
        window.ScoutMagicConfirm = undefined;
        await load();

        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        expect(form.requestSubmit).toHaveBeenCalledTimes(1);
    });

    it('asks only once, so the confirmed save is not intercepted again', async () => {
        const form = buildEditor({ originalDate: '2026-03-14', icsAlreadySent: '1', date: '2026-03-21' });
        const ask = vi.fn().mockResolvedValue(true);
        // @ts-ignore
        window.ScoutMagicConfirm = { ask, prompt: vi.fn() };
        await load();

        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await Promise.resolve();
        await Promise.resolve();

        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        expect(ask).toHaveBeenCalledTimes(1);
    });
});
