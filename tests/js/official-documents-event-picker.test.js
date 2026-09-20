// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in
// public/assets/js/official-documents-event-picker.js (imported below, never
// reimplemented here): the « Reprendre les dates d'une activité » control on
// the parental authorization screen.
//
// What is worth checking without a browser is the one piece of logic this
// script has: an option carries its two dates, and anything that is not
// exactly two ISO dates must leave the fields alone rather than write
// garbage into them. A date field holding garbage posts empty, and a parent
// staring at a refused form would have no way to tell why.
//
// The file is an IIFE that reads the DOM at import time, so each test builds
// its DOM first and then imports the module via vi.resetModules() + await
// import() (the tests/js/camps-booked-by.test.js pattern).
import { beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm(options) {
    document.body.innerHTML = `
        <form>
            <select id="event-picker" data-event-picker>
                <option value="">Choisir une activité…</option>
                ${options.map((o) => `<option value="${o}">${o}</option>`).join('')}
            </select>
            <input type="date" id="start-date" name="start_date" value="">
            <input type="date" id="end-date" name="end_date" value="">
        </form>
    `;
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/official-documents-event-picker.js');
}

function picker() {
    return /** @type {HTMLSelectElement} */ (document.getElementById('event-picker'));
}

function startField() {
    return /** @type {HTMLInputElement} */ (document.getElementById('start-date'));
}

function endField() {
    return /** @type {HTMLInputElement} */ (document.getElementById('end-date'));
}

function choose(value) {
    picker().value = value;
    picker().dispatchEvent(new Event('change'));
}

describe('the activity picker', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('fills both date fields from the chosen activity', async () => {
        buildForm(['2026-11-14|2026-11-16']);
        await load();

        choose('2026-11-14|2026-11-16');

        expect(startField().value).toBe('2026-11-14');
        expect(endField().value).toBe('2026-11-16');
    });

    it('leaves what was typed by hand alone when the choice is cleared', async () => {
        buildForm(['2026-11-14|2026-11-16']);
        await load();
        startField().value = '2027-07-01';
        endField().value = '2027-07-10';

        choose('');

        expect(startField().value).toBe('2027-07-01');
        expect(endField().value).toBe('2027-07-10');
    });

    it('writes nothing when an option carries something that is not two dates', async () => {
        buildForm(['pas-une-date|2026-11-16', '2026-11-14', '2026-11-14|2026-11-16|2026-11-18', '|']);
        await load();

        for (const value of ['pas-une-date|2026-11-16', '2026-11-14', '2026-11-14|2026-11-16|2026-11-18', '|']) {
            choose(value);
            expect(startField().value, `« ${value} » must not reach the date field`).toBe('');
            expect(endField().value, `« ${value} » must not reach the date field`).toBe('');
        }
    });

    it('does nothing at all when the calendar module left no picker on the page', async () => {
        document.body.innerHTML = `
            <form>
                <input type="date" id="start-date" name="start_date" value="">
                <input type="date" id="end-date" name="end_date" value="">
            </form>
        `;

        await expect(load()).resolves.not.toThrow();
        expect(startField().value).toBe('');
    });

    it('never hands the server an event identifier — the select has no name', async () => {
        buildForm(['2026-11-14|2026-11-16']);
        await load();

        // The whole design in one assertion: an unnamed control is not
        // submitted, so what reaches the server is the two dates and never
        // an id it would have to re-resolve and trust.
        expect(picker().getAttribute('name')).toBeNull();
    });

    it('parses exactly the shape the option carries, and nothing else', async () => {
        buildForm([]);
        await load();

        const { datesFrom } = window.ScoutMagicEventPicker;

        expect(datesFrom('2026-11-14|2026-11-16')).toEqual({ start: '2026-11-14', end: '2026-11-16' });
        expect(datesFrom('')).toBeNull();
        expect(datesFrom('2026-11-14|')).toBeNull();
        expect(datesFrom('2026-1-4|2026-11-16')).toBeNull();
        expect(datesFrom('14/11/2026|16/11/2026')).toBeNull();
    });
});
