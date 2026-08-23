// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked, the site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed, and the `bootstrap` bundle is
// absent on purpose — calendar-chief.js falls back to showing the dialog by
// hand, which is exactly the branch a jsdom run can observe.
//
// Exercises the REAL public/assets/js/calendar-chief.js (imported below,
// never reimplemented) on top of the real api.js envelope. The file is an
// IIFE that wires itself against the DOM present at import time, so every
// test builds its fixture first and imports afterwards — the
// tests/js/finance-categories.test.js pattern.
//
// The populate-before-reveal ordering of the linked-rétrospective link has
// its own suite, tests/js/calendar-chief-retro-link-order.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** @param {any} data */
function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

// What a PHP fatal error actually looks like from the browser: HTTP 500 and
// a body that is not JSON at all, so res.json() rejects.
function htmlErrorResponse() {
    return Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new SyntaxError('Unexpected token <')) });
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const EVENT_DATA = {
    id: '42',
    'calendar-id': '3',
    title: 'Camp',
    'start-date': '2026-07-01',
    'end-date': '2026-07-08',
    'start-time': '09:00',
    'end-time': '18:00',
    location: 'Ardenne',
    description: 'Camp d\'été',
    'has-linked-retro': '0',
    'auto-create-retro': '1',
    'retro-link': '',
    'retro-link-title': '',
};

function eventBarAttributes() {
    return Object.entries(EVENT_DATA).map(([key, value]) => `data-${key}="${value}"`).join(' ');
}

function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="calendar-week">
            <button type="button" class="calendar-day-cell calendar-day-cell--clickable" data-date="2026-07-15"></button>
            <button type="button" class="calendar-event-bar calendar-event-bar--clickable" ${eventBarAttributes()}>Camp</button>
        </div>

        <div class="modal fade" id="eventModal" tabindex="-1">
            <div class="modal-content">
                <h2 id="eventModal-title">Ajouter un évènement</h2>
                <form id="event-form">
                    <input type="hidden" id="event-id" value="">
                    <input type="text" id="event-title">
                    <select id="event-calendar">
                        <option value="1">Louveteaux</option>
                        <option value="3">Éclaireurs</option>
                    </select>
                    <input type="date" id="event-start-date">
                    <input type="date" id="event-end-date">
                    <input type="time" id="event-start-time">
                    <input type="time" id="event-end-time">
                    <input type="text" id="event-location">
                    <textarea id="event-description"></textarea>
                    <div class="mb-2 d-none" id="event-retro-auto-create-wrap">
                        <input type="checkbox" id="event-retro-auto-create">
                    </div>
                    <div class="mb-2 d-none small" id="event-retro-link-wrap">
                        <a href="#" id="event-retro-link"></a>
                    </div>
                    <div class="text-danger small d-none" id="event-form-error" role="alert"></div>
                </form>
                <button type="button" class="d-none" id="event-form-delete">Supprimer</button>
                <button type="submit" form="event-form" id="event-form-submit">Ajouter</button>
            </div>
        </div>
    `;
}

/** @param {object} [pageData] */
async function boot(pageData) {
    buildDom();
    window.calendarChiefData = pageData === undefined
        ? {
            defaultTitle: 'Réunion',
            defaultStartTime: '14:00',
            defaultEndTime: '17:00',
            defaultLocation: 'Local',
            defaultCalendarId: 3,
        }
        : pageData;
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/calendar-chief.js');
}

/**
 * Every request body posted to `url`, parsed.
 *
 * @param {string} url
 * @returns {any[]}
 */
function postsTo(url) {
    return fetch.mock.calls
        .filter((call) => call[0] === url)
        .map((call) => JSON.parse(call[1].body));
}

function openAdd() {
    document.querySelector('.calendar-day-cell--clickable').dispatchEvent(new Event('click', { bubbles: true }));
}

function openEdit() {
    document.querySelector('.calendar-event-bar--clickable').dispatchEvent(new Event('click', { bubbles: true }));
}

function submitForm() {
    document.getElementById('event-form').dispatchEvent(new Event('submit'));
}

/** @param {string} id @returns {any} */
function el(id) {
    return document.getElementById(id);
}

beforeEach(() => {
    vi.resetModules();
    vi.restoreAllMocks();
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    delete window.bootstrap;
    delete window.calendarChiefData;
    Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
});

describe('calendar-chief.js: entry guard', () => {
    it('does nothing at all on a page without the event dialog', async () => {
        document.head.innerHTML = '';
        document.body.innerHTML = '<p>Une autre page</p>';
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await expect(import('../../public/assets/js/calendar-chief.js')).resolves.toBeDefined();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('calendar-chief.js: adding an event', () => {
    it('pre-fills the dialog with the unit defaults handed over by window.calendarChiefData', async () => {
        await boot();

        openAdd();

        expect(el('event-id').value).toBe('');
        expect(el('event-title').value).toBe('Réunion');
        expect(el('event-calendar').value).toBe('3');
        expect(el('event-start-date').value).toBe('2026-07-15');
        expect(el('event-end-date').value).toBe('2026-07-15');
        expect(el('event-start-time').value).toBe('14:00');
        expect(el('event-end-time').value).toBe('17:00');
        expect(el('event-location').value).toBe('Local');
        expect(el('eventModal-title').textContent).toBe('Ajouter un évènement');
        expect(el('event-form-submit').textContent).toBe('Ajouter');
        expect(el('event-form-delete').classList.contains('d-none')).toBe(true);
    });

    it('opens on an empty dialog rather than throwing when the page ships no defaults', async () => {
        await boot({});

        expect(() => openAdd()).not.toThrow();
        expect(el('event-title').value).toBe('');
        expect(el('event-location').value).toBe('');
        // No default calendar: the select keeps whatever it was showing.
        expect(el('event-calendar').value).toBe('1');
    });

    it('posts a creation with the CSRF token in the body AND the header, then reloads', async () => {
        await boot();
        openAdd();
        el('event-title').value = 'Réunion de staff';

        submitForm();
        await settle();

        expect(postsTo('/chefs/calendar/event-create')).toEqual([{
            calendar_id: 3,
            title: 'Réunion de staff',
            start_date: '2026-07-15',
            end_date: '2026-07-15',
            start_time: '14:00',
            end_time: '17:00',
            location: 'Local',
            description: '',
            auto_create_retro: false,
            _csrf_token: 'tok',
        }]);
        expect(fetch.mock.calls[0][1].headers['X-CSRF-Token']).toBe('tok');
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('sends auto_create_retro only while the rétrospective checkbox is on screen', async () => {
        await boot();
        openAdd();
        el('event-retro-auto-create').checked = true;

        submitForm();
        await settle();
        expect(postsTo('/chefs/calendar/event-create')[0].auto_create_retro).toBe(true);

        fetch.mockClear();
        el('event-retro-auto-create-wrap').classList.add('d-none');
        submitForm();
        await settle();
        expect(postsTo('/chefs/calendar/event-create')[0]).not.toHaveProperty('auto_create_retro');
    });
});

describe('calendar-chief.js: editing an event', () => {
    it('fills the dialog from the clicked bar and switches it to update mode', async () => {
        await boot();

        openEdit();

        expect(el('event-id').value).toBe('42');
        expect(el('event-calendar').value).toBe('3');
        expect(el('event-title').value).toBe('Camp');
        expect(el('event-start-date').value).toBe('2026-07-01');
        expect(el('event-end-date').value).toBe('2026-07-08');
        expect(el('event-location').value).toBe('Ardenne');
        expect(el('event-description').value).toBe("Camp d'été");
        expect(el('eventModal-title').textContent).toBe("Modifier l'évènement");
        expect(el('event-form-submit').textContent).toBe('Enregistrer');
        expect(el('event-form-delete').classList.contains('d-none')).toBe(false);
        expect(el('event-retro-auto-create').checked).toBe(true);
    });

    it('stops the click from reaching the day cell underneath', async () => {
        await boot();
        const bubbled = vi.fn();
        document.body.addEventListener('click', bubbled);

        openEdit();

        expect(bubbled).not.toHaveBeenCalled();
        // And the dialog is the edit one, not the "add an event" one the
        // day cell would have opened.
        expect(el('eventModal-title').textContent).toBe("Modifier l'évènement");
        document.body.removeEventListener('click', bubbled);
    });

    it('posts an update carrying the event id', async () => {
        await boot();
        openEdit();

        submitForm();
        await settle();

        expect(fetch.mock.calls.map((call) => call[0])).toEqual(['/chefs/calendar/event-update']);
        expect(postsTo('/chefs/calendar/event-update')[0]).toMatchObject({
            event_id: 42,
            calendar_id: 3,
            title: 'Camp',
            _csrf_token: 'tok',
        });
    });

    it('shows the server error in the dialog, as text rather than markup, and does not reload', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)> Date de fin antérieure.' }));
        await boot();
        openEdit();

        submitForm();
        await settle();

        const box = el('event-form-error');
        expect(box.classList.contains('d-none')).toBe(false);
        expect(box.textContent).toBe('<img src=x onerror=alert(1)> Date de fin antérieure.');
        expect(box.querySelector('img')).toBeNull();
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('shows a French line — never "undefined" — when the server answers an HTML 500 page', async () => {
        // The HTTP-ok / business-success conflation, from the other side: a
        // body that is not JSON has no data.error to print.
        global.fetch = vi.fn(() => htmlErrorResponse());
        await boot();
        openAdd();

        submitForm();
        await settle();

        expect(el('event-form-error').textContent).toBe('Erreur : réponse serveur invalide.');
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('calendar-chief.js: deleting an event', () => {
    it('asks the shared confirmation — never the native box — with the French message and « Supprimer »', async () => {
        await boot();
        openEdit();

        el('event-form-delete').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Supprimer cet évènement ?',
            confirmLabel: 'Supprimer',
        });
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('sends nothing and reloads nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();
        openEdit();

        el('event-form-delete').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('posts the event id to the delete endpoint and reloads when it resolves true', async () => {
        await boot();
        openEdit();

        el('event-form-delete').dispatchEvent(new Event('click'));
        await settle();

        expect(postsTo('/chefs/calendar/event-delete')).toEqual([
            { event_id: 42, _csrf_token: 'tok' },
        ]);
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('shows the refusal in the dialog instead of failing silently', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Évènement introuvable.' }));
        await boot();
        openEdit();

        el('event-form-delete').dispatchEvent(new Event('click'));
        await settle();

        const box = el('event-form-error');
        expect(box.textContent).toBe('Évènement introuvable.');
        expect(box.classList.contains('d-none')).toBe(false);
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});
