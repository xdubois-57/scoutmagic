// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
// Exercises the REAL public/assets/js/retro-config.js (imported below,
// never reimplemented): the file is an IIFE that wires the retro
// create/edit form at import time.
//
// The file's own header records why it exists as a file at all: the
// event-picker date sync and the slider's live value never worked, because
// they were an inline <script> and an inline oninput= under a nonce-only
// CSP — silently blocked, no error anywhere the author would look. Moving
// them out fixed that; nothing then proved they work. This does.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadForm(html) {
    document.body.innerHTML = html;
    vi.resetModules();
    await import('../../public/assets/js/retro-config.js');
}

/** @param {string} id @returns {HTMLInputElement} */
function input(id) {
    return /** @type {HTMLInputElement} */ (document.getElementById(id));
}

const EVENT_PICKER = `
    <select id="retro-event">
        <option value="">Aucun évènement</option>
        <option value="7"
                data-end-date="2026-07-14"
                data-start-date="2026-07-05"
                data-title="Camp d'été"
                data-calendar-name="Éclaireurs">Camp d'été</option>
        <option value="9">Réunion sans dates</option>
    </select>
    <input id="retro-date" type="date">
    <input id="retro-title" type="text">
`;

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('retro-config.js vote mode', () => {
    const VOTE_MODE = `
        <input type="radio" name="vote_mode" value="simple" data-vote-mode checked>
        <input type="radio" name="vote_mode" value="budget" data-vote-mode>
        <div id="retro-vote-budget-field" class="d-none"></div>
    `;

    it('reveals the budget field only for the budget mode', async () => {
        await loadForm(VOTE_MODE);
        const [simple, budget] = [...document.querySelectorAll('[data-vote-mode]')];
        const field = document.getElementById('retro-vote-budget-field');

        budget.dispatchEvent(new window.Event('change'));
        expect(field.classList.contains('d-none')).toBe(false);

        simple.dispatchEvent(new window.Event('change'));
        expect(field.classList.contains('d-none')).toBe(true);
    });

    it('does not throw when the budget field is absent', async () => {
        await loadForm('<input type="radio" value="budget" data-vote-mode>');
        const radio = document.querySelector('[data-vote-mode]');

        expect(() => radio.dispatchEvent(new window.Event('change'))).not.toThrow();
    });
});

describe('retro-config.js event picker', () => {
    it('fills and locks the date and title from the chosen event', async () => {
        await loadForm(EVENT_PICKER);
        const select = /** @type {HTMLSelectElement} */ (document.getElementById('retro-event'));

        select.value = '7';
        select.dispatchEvent(new window.Event('change'));

        // The retro is about the event, so its date is the event's end and
        // neither field is the visitor's to edit any more.
        expect(input('retro-date').value).toBe('2026-07-14');
        expect(input('retro-date').disabled).toBe(true);
        expect(input('retro-title').value).toBe("Rétrospective Camp d'été - Éclaireurs - 2026-07-05");
        expect(input('retro-title').disabled).toBe(true);
    });

    it('hands both fields back when the event is cleared', async () => {
        await loadForm(EVENT_PICKER);
        const select = /** @type {HTMLSelectElement} */ (document.getElementById('retro-event'));

        select.value = '7';
        select.dispatchEvent(new window.Event('change'));
        select.value = '';
        select.dispatchEvent(new window.Event('change'));

        expect(input('retro-date').disabled).toBe(false);
        expect(input('retro-title').disabled).toBe(false);
    });

    it('leaves the date editable for an event that carries no end date', async () => {
        await loadForm(EVENT_PICKER);
        const select = /** @type {HTMLSelectElement} */ (document.getElementById('retro-event'));

        select.value = '9';
        select.dispatchEvent(new window.Event('change'));

        expect(input('retro-date').disabled).toBe(false);
        expect(input('retro-date').value).toBe('');
        // The title still locks: it is composed from whatever the event has.
        expect(input('retro-title').disabled).toBe(true);
    });

    it('works on a form that has no title field', async () => {
        await loadForm(`
            <select id="retro-event">
                <option value="">Aucun</option>
                <option value="7" data-end-date="2026-07-14">Camp</option>
            </select>
            <input id="retro-date" type="date">
        `);
        const select = /** @type {HTMLSelectElement} */ (document.getElementById('retro-event'));

        select.value = '7';
        expect(() => select.dispatchEvent(new window.Event('change'))).not.toThrow();
        expect(input('retro-date').value).toBe('2026-07-14');
    });
});

describe('retro-config.js live slider value', () => {
    it('mirrors the slider into its readout', async () => {
        // min/max matter: a range input clamps to 0-100 by default, so a
        // fixture without them silently tests the clamp instead.
        await loadForm('<input type="range" id="retro-max-length" min="100" max="500" value="200"><span id="retro-max-length-value">200</span>');

        input('retro-max-length').value = '350';
        input('retro-max-length').dispatchEvent(new window.Event('input'));

        expect(document.getElementById('retro-max-length-value').textContent).toBe('350');
    });
});

describe('retro-config.js notification email', () => {
    it('disables the address as soon as the page loads unchecked', async () => {
        await loadForm('<input type="checkbox" id="retro-notify-enabled"><input id="retro-notify-email">');

        // Synced at load, not only on change: a form rendered with the
        // notification off would otherwise show an editable address that
        // goes nowhere.
        expect(input('retro-notify-email').disabled).toBe(true);
    });

    it('follows the checkbox both ways', async () => {
        await loadForm('<input type="checkbox" id="retro-notify-enabled" checked><input id="retro-notify-email">');
        expect(input('retro-notify-email').disabled).toBe(false);

        input('retro-notify-enabled').checked = false;
        input('retro-notify-enabled').dispatchEvent(new window.Event('change'));
        expect(input('retro-notify-email').disabled).toBe(true);

        input('retro-notify-enabled').checked = true;
        input('retro-notify-enabled').dispatchEvent(new window.Event('change'));
        expect(input('retro-notify-email').disabled).toBe(false);
    });
});

describe('retro-config.js on a page that is not the retro form', () => {
    it('wires nothing and throws nothing', async () => {
        await expect(loadForm('<p>Une autre page</p>')).resolves.not.toThrow();
    });
});
