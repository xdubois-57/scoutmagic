// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP server, no
// network. Exercises the REAL implementation in
// public/assets/js/rental-calendar.js (imported below, never reimplemented
// here): the two-tap range selection on an asset's public availability
// calendar (module spec §6.7).
//
// The script decides nothing — every tap produces a URL and the server
// recomputes states, prices and validation. So what is worth testing in
// isolation is exactly the two pure functions: which date a tap sets, and
// what URL that becomes.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    estimateReady,
    estimateUrl,
    fragmentUrl,
    nextSelection,
    selectionUrl,
    wireCalendar,
} from '../../public/assets/js/rental-calendar.js';

describe('nextSelection', () => {
    it('sets the arrival on the first tap', () => {
        expect(nextSelection('2027-07-17', '', '')).toEqual({
            arrival: '2027-07-17',
            departure: '',
        });
    });

    it('sets the departure on the second tap', () => {
        expect(nextSelection('2027-07-20', '2027-07-17', '')).toEqual({
            arrival: '2027-07-17',
            departure: '2027-07-20',
        });
    });

    it('starts a new selection once a complete range exists', () => {
        expect(nextSelection('2027-08-01', '2027-07-17', '2027-07-20')).toEqual({
            arrival: '2027-08-01',
            departure: '',
        });
    });

    it('restarts from an earlier day rather than producing a reversed range', () => {
        // How a visitor changes their mind. Refusing it would strand them
        // with no way back except reloading the page.
        expect(nextSelection('2027-07-10', '2027-07-17', '')).toEqual({
            arrival: '2027-07-10',
            departure: '',
        });
    });

    it('clears the selection when the arrival is tapped again', () => {
        // Undo a mis-tap with the same gesture that made it.
        expect(nextSelection('2027-07-17', '2027-07-17', '')).toEqual({
            arrival: '',
            departure: '',
        });
    });

    it('allows a same-day departure to be chosen explicitly', () => {
        // Valid for a full-days asset (a trailer taken and returned the same
        // day); the server decides whether the billing model accepts it.
        expect(nextSelection('2027-07-17', '2027-07-17', '2027-07-20')).toEqual({
            arrival: '2027-07-17',
            departure: '',
        });
    });

    it('leaves the selection untouched for an empty date', () => {
        expect(nextSelection('', '2027-07-17', '2027-07-20')).toEqual({
            arrival: '2027-07-17',
            departure: '2027-07-20',
        });
    });
});

describe('selectionUrl', () => {
    it('adds both dates to the query string', () => {
        const url = selectionUrl('/locations/local?month=2027-07', {
            arrival: '2027-07-17',
            departure: '2027-07-20',
        });

        expect(url).toContain('arrival=2027-07-17');
        expect(url).toContain('departure=2027-07-20');
        expect(url).toContain('month=2027-07');
        expect(url.startsWith('/locations/local?')).toBe(true);
    });

    it('drops the departure when only the arrival is chosen', () => {
        const url = selectionUrl('/locations/local?arrival=2027-07-01&departure=2027-07-05', {
            arrival: '2027-07-17',
            departure: '',
        });

        expect(url).toContain('arrival=2027-07-17');
        expect(url).not.toContain('departure=');
    });

    it('drops both when the selection is cleared', () => {
        const url = selectionUrl('/locations/local?arrival=2027-07-01&departure=2027-07-05&month=2027-07', {
            arrival: '',
            departure: '',
        });

        expect(url).not.toContain('arrival=');
        expect(url).not.toContain('departure=');
        expect(url).toContain('month=2027-07');
    });

    it('preserves query parameters it knows nothing about', () => {
        // A parameter a later iteration adds must survive a tap rather than
        // being silently dropped.
        const url = selectionUrl('/locations/local?persons=35&category=2&something=else', {
            arrival: '2027-07-17',
            departure: '',
        });

        expect(url).toContain('persons=35');
        expect(url).toContain('category=2');
        expect(url).toContain('something=else');
    });

    it('never leaks the placeholder origin used to parse a relative URL', () => {
        const url = selectionUrl('/locations/local', { arrival: '2027-07-17', departure: '' });

        expect(url).not.toContain('placeholder.invalid');
        expect(url).not.toContain('https://');
    });
});

describe('wireCalendar', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    function buildCalendar({ arrival = '', departure = '' } = {}) {
        const container = document.createElement('div');
        container.id = 'rental-calendar';
        container.setAttribute('data-arrival', arrival);
        container.setAttribute('data-departure', departure);

        ['2027-07-17', '2027-07-18', '2027-07-20'].forEach((date) => {
            const cell = document.createElement('button');
            cell.setAttribute('data-date', date);
            cell.textContent = date;
            container.appendChild(cell);
        });

        document.body.appendChild(container);

        return container;
    }

    it('navigates to the URL a tap produces', () => {
        const container = buildCalendar();
        const assigned = [];
        // jsdom refuses a real navigation; capture the assignment instead.
        delete (/** @type {any} */ (window)).location;
        (/** @type {any} */ (window)).location = {
            href: 'https://example.org/locations/local?month=2027-07',
        };
        Object.defineProperty(window.location, 'href', {
            get: () => 'https://example.org/locations/local?month=2027-07',
            set: (value) => assigned.push(value),
            configurable: true,
        });

        wireCalendar(container);
        /** @type {HTMLElement} */ (container.querySelector('[data-date="2027-07-17"]')).click();

        expect(assigned).toHaveLength(1);
        expect(assigned[0]).toContain('arrival=2027-07-17');
        expect(assigned[0]).toContain('month=2027-07');
    });

    it('ignores a click that lands outside any day cell', () => {
        const container = buildCalendar();
        const assigned = [];
        delete (/** @type {any} */ (window)).location;
        (/** @type {any} */ (window)).location = { href: 'https://example.org/locations/local' };
        Object.defineProperty(window.location, 'href', {
            get: () => 'https://example.org/locations/local',
            set: (value) => assigned.push(value),
            configurable: true,
        });

        const filler = document.createElement('span');
        container.appendChild(filler);

        wireCalendar(container);
        filler.click();

        expect(assigned).toHaveLength(0);
    });

    it('reads the current selection from the container, not from memory', () => {
        // The server owns the selection; the script must not keep its own
        // copy that could drift from what the page actually shows.
        const container = buildCalendar({ arrival: '2027-07-17' });
        const assigned = [];
        delete (/** @type {any} */ (window)).location;
        (/** @type {any} */ (window)).location = { href: 'https://example.org/locations/local' };
        Object.defineProperty(window.location, 'href', {
            get: () => 'https://example.org/locations/local',
            set: (value) => assigned.push(value),
            configurable: true,
        });

        wireCalendar(container);
        /** @type {HTMLElement} */ (container.querySelector('[data-date="2027-07-20"]')).click();

        expect(assigned[0]).toContain('arrival=2027-07-17');
        expect(assigned[0]).toContain('departure=2027-07-20');
    });
});

describe('estimateUrl and estimateReady', () => {
    /**
     * @param {Record<string, string>} values
     * @returns {HTMLFormElement}
     */
    function buildForm(values) {
        const form = document.createElement('form');
        Object.entries(values).forEach(([name, value]) => {
            const field = document.createElement('input');
            field.name = name;
            field.value = value;
            form.appendChild(field);
        });
        document.body.appendChild(form);

        return form;
    }

    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('builds the page URL from the non-empty fields only', () => {
        // A blank field means "not chosen", not "chosen as empty": carrying
        // it would read back server-side as a head count of zero.
        const form = buildForm({
            month: '2027-07',
            estimate: '1',
            arrival: '2027-07-17',
            departure: '2027-07-20',
            persons: '',
        });

        const url = estimateUrl(form, 'https://unite.test/locations/local?month=2027-06');

        expect(url).toBe('/locations/local?month=2027-07&estimate=1&arrival=2027-07-17&departure=2027-07-20');
        expect(url).not.toContain('persons=');
    });

    it('is ready only once both dates are present', () => {
        // One date alone prices nothing, and refreshing on it would wipe
        // what the visitor is in the middle of typing.
        expect(estimateReady(buildForm({ arrival: '2027-07-17', departure: '2027-07-20' }))).toBe(true);
        expect(estimateReady(buildForm({ arrival: '2027-07-17', departure: '' }))).toBe(false);
        expect(estimateReady(buildForm({ arrival: '', departure: '2027-07-20' }))).toBe(false);
        expect(estimateReady(buildForm({ persons: '20' }))).toBe(false);
    });
});

describe('fragmentUrl', () => {
    it('appends /apercu to the asset path and keeps the whole query string', () => {
        expect(fragmentUrl('https://unite.test/locations/local?month=2027-07&arrival=2027-07-17'))
            .toBe('/locations/local/apercu?month=2027-07&arrival=2027-07-17');
    });

    it('works on a bare asset URL', () => {
        expect(fragmentUrl('https://unite.test/locations/local')).toBe('/locations/local/apercu');
    });

    it('does not double a trailing slash', () => {
        expect(fragmentUrl('https://unite.test/locations/local/')).toBe('/locations/local/apercu');
    });

    it('carries a parameter it knows nothing about', () => {
        // The fragment and the full page must be given identical input, or
        // they answer differently.
        expect(fragmentUrl('https://unite.test/locations/local?futur=1'))
            .toBe('/locations/local/apercu?futur=1');
    });
});
