// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network. Exercises the REAL implementation in
// public/assets/js/camps-place-summary.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so the fixture is built first and the module imported via
// vi.resetModules() + await import().
//
// The fixture mirrors modules/camps/views/place.html.twig: the two forms
// that ask the LLM connector for a place's summary, both posting to the
// same action.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <form method="post" action="/chefs/camps/lieux/4/resume" data-summary-form>
        <button type="submit" class="btn btn-link btn-sm p-0">Régénérer</button>
    </form>
    <form method="post" action="/chefs/camps/lieux/4/resume">
        <button type="submit">Sans marqueur</button>
    </form>`;

async function load() {
    vi.resetModules();
    document.body.innerHTML = PAGE;
    await import('../../public/assets/js/camps-place-summary.js');
}

/** @returns {HTMLButtonElement} */
function marked() {
    return document.querySelector('[data-summary-form] button');
}

describe('camps-place-summary.js', () => {
    beforeEach(async () => {
        await load();
    });

    it('says the button is working, rather than looking inert', () => {
        // Behind this action is a call to a paid API that takes seconds.
        // A button that does not react gets pressed twice.
        document.querySelector('[data-summary-form]').dispatchEvent(new Event('submit'));

        expect(marked().disabled).toBe(true);
        expect(marked().innerHTML).toContain('spinner-border');
        expect(marked().textContent).toContain('Rédaction en cours');
    });

    it('leaves a form that did not ask for it alone', () => {
        const other = document.querySelectorAll('form')[1];
        other.dispatchEvent(new Event('submit'));

        expect(other.querySelector('button').disabled).toBe(false);
    });

    it('does not lose the words it replaced', () => {
        const form = document.querySelector('[data-summary-form]');
        form.dispatchEvent(new Event('submit'));

        window.ScoutMagicCampsPlaceSummary.release(form);

        expect(marked().disabled).toBe(false);
        expect(marked().innerHTML).toBe('Régénérer');
        expect(marked().dataset.idleLabel).toBeUndefined();
    });

    it('a second submit does not overwrite the label with the spinner', () => {
        // Otherwise coming back would restore « Rédaction en cours… » as
        // the button's idle wording, permanently.
        const form = document.querySelector('[data-summary-form]');
        form.dispatchEvent(new Event('submit'));
        form.dispatchEvent(new Event('submit'));

        window.ScoutMagicCampsPlaceSummary.release(form);

        expect(marked().innerHTML).toBe('Régénérer');
    });

    it('re-enables the button on a page restored from the browser cache', () => {
        // The back button hands back the page exactly as it was left,
        // disabled button included — and nothing else would ever undo it,
        // because the normal end of this flow is a new page.
        document.querySelector('[data-summary-form]').dispatchEvent(new Event('submit'));
        expect(marked().disabled).toBe(true);

        const restored = new Event('pageshow');
        Object.defineProperty(restored, 'persisted', { value: true });
        window.dispatchEvent(restored);

        expect(marked().disabled).toBe(false);
        expect(marked().innerHTML).toBe('Régénérer');
    });

    it('leaves an ordinary load alone', () => {
        document.querySelector('[data-summary-form]').dispatchEvent(new Event('submit'));

        const fresh = new Event('pageshow');
        Object.defineProperty(fresh, 'persisted', { value: false });
        window.dispatchEvent(fresh);

        expect(marked().disabled).toBe(true);
    });
});
