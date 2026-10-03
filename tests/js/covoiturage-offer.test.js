// Isolated JavaScript unit test — jsdom DOM only, fetch mocked. Exercises
// the REAL public/assets/js/covoiturage-offer.js (imported below, never
// reimplemented): the suggested departure of the carpool offer form
// (#703), asked again when the meeting point changes value, and the return
// field shown only while its box is ticked.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const FORM = `
    <form id="offer-form" data-travel-url="/covoiturage/7/trajet" data-direction="outbound">
        <input id="offer-time" type="time" value="13:30">
        <p id="offer-time-suggestion">Heure suggérée : 13 h 30 — début à 14 h 00, trajet non calculé : 30 min avant.</p>
        <input id="offer-endpoint" value="Local des Louveteaux">
        <input id="offer-also-return" type="checkbox">
        <div id="offer-return-block" hidden>
            <input id="offer-return-time" type="time" value="17:00">
            <p id="offer-return-suggestion">Arrivée non estimée : trajet non calculé.</p>
        </div>
    </form>`;

const ANSWER = {
    minutes: 42,
    outbound: { time: '13:13', line: 'Heure suggérée : 13 h 13 — début à 14 h 00, trajet estimé à 42 min, plus 5 min de marge.' },
    return: { time: '17:00', arrival: '17:42', line: 'Heure suggérée : 17 h 00, à la fin de l\'activité. Arrivée estimée : 17 h 42 (trajet estimé à 42 min).' },
};

function respond(body) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/covoiturage-offer.js');
}

const $ = (id) => document.getElementById(id);
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = FORM;
    global.fetch = vi.fn(() => respond(ANSWER));
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('covoiturage-offer.js', () => {
    it('does nothing on a page without the offer form', async () => {
        document.body.innerHTML = '<p>Autre page</p>';
        await boot();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('asks the route once on arrival for a pre-filled meeting point, through the site only', async () => {
        await boot();
        await settle();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toBe('/covoiturage/7/trajet?depuis=Local%20des%20Louveteaux');
        expect($('offer-time').value).toBe('13:13');
        expect($('offer-time-suggestion').textContent).toContain('trajet estimé à 42 min');
        expect($('offer-return-suggestion').textContent).toContain('Arrivée estimée : 17 h 42');
    });

    it('asks again when the meeting point changes value, never per keystroke', async () => {
        await boot();
        await settle();
        fetch.mockClear();

        $('offer-endpoint').value = 'Gare de Wavre';
        $('offer-endpoint').dispatchEvent(new Event('input'));
        expect(fetch).not.toHaveBeenCalled();

        $('offer-endpoint').dispatchEvent(new Event('change'));
        await settle();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toContain('depuis=Gare%20de%20Wavre');
    });

    it('keeps an hour the driver typed, while the sentence still says the suggestion', async () => {
        await boot();
        await settle();

        $('offer-time').value = '12:45';
        $('offer-time').dispatchEvent(new Event('input'));
        global.fetch = vi.fn(() => respond({ ...ANSWER, outbound: { time: '13:00', line: 'Heure suggérée : 13 h 00.' } }));
        $('offer-endpoint').dispatchEvent(new Event('change'));
        await settle();

        expect($('offer-time').value).toBe('12:45');
        expect($('offer-time-suggestion').textContent).toBe('Heure suggérée : 13 h 00.');
    });

    it('falls back silently when the route cannot be had', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();
        await settle();

        expect($('offer-time').value).toBe('13:30');
        expect($('offer-time-suggestion').textContent).toContain('trajet non calculé');
    });

    it('shows the return field only while its box is ticked', async () => {
        await boot();
        expect($('offer-return-block').hidden).toBe(true);

        $('offer-also-return').checked = true;
        $('offer-also-return').dispatchEvent(new Event('change'));
        expect($('offer-return-block').hidden).toBe(false);

        $('offer-also-return').checked = false;
        $('offer-also-return').dispatchEvent(new Event('change'));
        expect($('offer-return-block').hidden).toBe(true);
    });
});
