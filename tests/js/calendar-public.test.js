// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, so are
// window.ScoutMagicConfirm, window.ScoutMagicToast, window.location.reload
// (jsdom does not implement navigation), navigator.clipboard (jsdom has
// none) and Bootstrap's Modal. Exercises the REAL implementation in
// public/assets/js/calendar-public.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors what modules/calendar/views/public.html.twig
// renders: one month-grid bar and one « Prochains évènements » row
// carrying the identical data-* bag, the shared modal embed's body, and
// the personal ICS link block (authenticated visitors only).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const FULL_EVENT = `
    <button type="button" class="calendar-event-bar--clickable"
            data-title="Camp d'été" data-color="#c00" data-calendar-label="Louveteaux"
            data-start-date="2026-07-04" data-end-date="2026-07-14"
            data-start-time="09:00" data-end-time="17:30"
            data-location="Vielsalm" data-description="Rendez-vous à la gare."></button>`;

const BARE_EVENT = `
    <button type="button" class="calendar-upcoming-event-trigger"
            data-title="Réunion" data-color="#06c" data-calendar-label="Unité"
            data-start-date="2026-03-08"></button>`;

const DIALOG = `
    <div id="eventDetailsModal">
        <h5 id="eventDetailsModal-title"></h5>
        <span id="event-details-calendar-dot"></span>
        <span id="event-details-calendar-label"></span>
        <span id="event-details-dates"></span>
        <div class="d-none" id="event-details-time-row"><span id="event-details-time"></span></div>
        <div class="d-none" id="event-details-location-row"><span id="event-details-location"></span></div>
        <p id="event-details-description"></p>
    </div>`;

const ICS_BLOCK = `
    <input type="text" id="personal-ics-link" readonly
           value="https://scoutmagic.be/calendar/feed/personal/abc.ics">
    <button type="button" id="copy-personal-link"></button>
    <button type="button" id="regenerate-personal-link"></button>`;

const PAGE = `${FULL_EVENT}${BARE_EVENT}${DIALOG}${ICS_BLOCK}`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('calendar-public.js', () => {
    let modalInstance;
    let writeText;

    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        modalInstance = { show: vi.fn(), hide: vi.fn() };
        window.bootstrap = { Modal: vi.fn(() => modalInstance) };
        writeText = vi.fn();
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/calendrier', reload: vi.fn() },
        });
        // jsdom implements select() on inputs, but not on a detached one.
        HTMLInputElement.prototype.select = vi.fn();
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/calendar-public.js');
    }

    const text = (id) => document.getElementById(id).textContent;
    const hidden = (id) => document.getElementById(id).classList.contains('d-none');

    describe('entry guard', () => {
        it('does nothing at all on a page with neither dialog nor ICS block', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(window.bootstrap.Modal).not.toHaveBeenCalled();
        });

        it('wires the dialog for a visitor who is not logged in (no ICS block)', async () => {
            document.body.innerHTML = `${FULL_EVENT}${DIALOG}`;
            await boot();
            document.querySelector('.calendar-event-bar--clickable').click();

            expect(modalInstance.show).toHaveBeenCalled();
        });
    });

    describe('event details', () => {
        it('fills every field from a bar\'s data-* bag and opens the dialog', async () => {
            await boot();
            document.querySelector('.calendar-event-bar--clickable').click();

            expect(text('eventDetailsModal-title')).toBe("Camp d'été");
            expect(text('event-details-calendar-label')).toBe('Louveteaux');
            expect(text('event-details-dates')).toBe('04/07/2026 — 14/07/2026');
            expect(text('event-details-time')).toBe('09:00 - 17:30');
            expect(text('event-details-location')).toBe('Vielsalm');
            expect(text('event-details-description')).toBe('Rendez-vous à la gare.');
            expect(document.getElementById('event-details-calendar-dot').style.backgroundColor)
                .toBe('rgb(204, 0, 0)');
            expect(modalInstance.show).toHaveBeenCalled();
        });

        it('reads « Prochains évènements » rows through the exact same path', async () => {
            await boot();
            document.querySelector('.calendar-upcoming-event-trigger').click();

            expect(text('eventDetailsModal-title')).toBe('Réunion');
            expect(text('event-details-dates')).toBe('08/03/2026');
        });

        it('hides the time and place rows for an all-day event with no place', async () => {
            await boot();
            document.querySelector('.calendar-upcoming-event-trigger').click();

            expect(hidden('event-details-time-row')).toBe(true);
            expect(hidden('event-details-location-row')).toBe(true);
            expect(text('event-details-description')).toBe('');
        });

        it('re-hides rows a previous event had revealed', async () => {
            await boot();
            document.querySelector('.calendar-event-bar--clickable').click();
            expect(hidden('event-details-time-row')).toBe(false);

            document.querySelector('.calendar-upcoming-event-trigger').click();
            expect(hidden('event-details-time-row')).toBe(true);
            expect(hidden('event-details-location-row')).toBe(true);
        });

        it('shows a single date when the event does not span days', async () => {
            document.querySelector('.calendar-event-bar--clickable')
                .setAttribute('data-end-date', '2026-07-04');
            await boot();
            document.querySelector('.calendar-event-bar--clickable').click();

            expect(text('event-details-dates')).toBe('04/07/2026');
        });

        it('shows only the start time when there is no end time', async () => {
            document.querySelector('.calendar-event-bar--clickable').removeAttribute('data-end-time');
            await boot();
            document.querySelector('.calendar-event-bar--clickable').click();

            expect(text('event-details-time')).toBe('09:00');
        });

        it('writes a title as TEXT — a « < » in an event name is not markup', async () => {
            document.querySelector('.calendar-event-bar--clickable')
                .setAttribute('data-title', '<img src=x onerror=alert(1)>');
            await boot();
            document.querySelector('.calendar-event-bar--clickable').click();

            const title = document.getElementById('eventDetailsModal-title');
            expect(title.querySelector('img')).toBeNull();
            expect(title.textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });

    describe('personal ICS link', () => {
        it('selects the field AND copies it — the selection is the fallback', async () => {
            await boot();
            document.getElementById('copy-personal-link').click();

            const input = document.getElementById('personal-ics-link');
            expect(input.select).toHaveBeenCalled();
            expect(writeText).toHaveBeenCalledWith('https://scoutmagic.be/calendar/feed/personal/abc.ics');
        });

        it('still selects the field on a browser with no clipboard API', async () => {
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
            await boot();

            expect(() => document.getElementById('copy-personal-link').click()).not.toThrow();
            expect(document.getElementById('personal-ics-link').select).toHaveBeenCalled();
        });

        it('asks before regenerating, naming what the old link loses', async () => {
            await boot();
            document.getElementById('regenerate-personal-link').click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message)
                .toMatch(/invalidera l'ancien/);
            expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].confirmLabel).toBe('Régénérer');
        });

        it('sends NOTHING when the answer is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();
            document.getElementById('regenerate-personal-link').click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(fetch).not.toHaveBeenCalled();
        });

        it('POSTs with the CSRF token and reloads on success', async () => {
            await boot();
            document.getElementById('regenerate-personal-link').click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/calendar/personal-token/regenerate');
            expect(opts.method).toBe('POST');
            expect(JSON.parse(opts.body)).toEqual({ _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('re-enables the button after the request, success or not', async () => {
            await boot();
            const btn = document.getElementById('regenerate-personal-link');
            btn.click();

            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
            expect(btn.disabled).toBe(false);
        });

        it('shows the server\'s own message on a refusal answered with HTTP 200', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Trop de régénérations.' }));
            await boot();
            document.getElementById('regenerate-personal-link').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Trop de régénérations.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('has a sentence of its own when the refusal carries none — never « undefined »', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false }));
            await boot();
            document.getElementById('regenerate-personal-link').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith("Le lien n'a pas pu être régénéré.", { variant: 'error' });
        });

        it('says so when the network dies', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            document.getElementById('regenerate-personal-link').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });
});
