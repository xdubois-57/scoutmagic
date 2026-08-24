// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed, so each confirmation's answer
// is something the test chooses rather than something a real modal has to
// be clicked for.
//
// Exercises the REAL public/assets/js/calendar-config.js (imported below,
// never reimplemented) on top of the real api.js envelope. The file is an
// IIFE that wires itself against the DOM present at import time, so every
// test builds its fixture first and imports afterwards — the
// tests/js/finance-categories.test.js pattern.
//
// What this suite owns above all: the three confirmations this page asks
// before it invalidates a shared ICS link or deletes a calendar (and the
// fact that a refusal reaches no endpoint at all), and the difference
// between HTTP success and business success — an HTML 500 page must never
// be read as « enregistré ».
import { beforeEach, describe, expect, it, vi } from 'vitest';

const REGENERATE_MESSAGE = "Régénérer ce lien invalidera l'ancien : tous les abonnements existants cesseront de se mettre à jour. Continuer ?";

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
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope plus the awaited confirmation add promise
    // layers a fixed number of Promise.resolve() awaits kept undercounting.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <form id="defaults-form">
            <input id="default-title" value="Réunion">
            <input id="default-start-time" value="14:00">
            <input id="default-end-time" value="17:00">
            <input id="default-location" value="Local">
            <button type="submit">Enregistrer</button>
        </form>

        <form id="notifications-form">
            <input type="checkbox" id="notify-enabled" role="switch" checked aria-checked="true">
            <input type="number" id="notify-days-before" value="14">
            <button type="submit">Enregistrer</button>
            <div class="d-none" id="notifications-error" role="alert"></div>
        </form>

        <select class="visibility-select" data-calendar-id="3">
            <option value="public" selected>Public</option>
            <option value="chief">Animateur</option>
            <option value="admin">Chef d'Unité</option>
        </select>
        <select class="edit-role-select" data-calendar-id="3">
            <option value="chief" selected>Modifié par ses animateurs</option>
            <option value="admin">Modifié par les chefs d'unité</option>
        </select>

        <div id="supplementary-calendar-list">
            <div class="border" data-calendar-id="7">
                <select class="visibility-select" data-calendar-id="7">
                    <option value="public" selected>Public</option>
                    <option value="admin">Chef d'Unité</option>
                </select>
                <select class="edit-role-select" data-calendar-id="7">
                    <option value="chief" selected>Modifié par les animateurs</option>
                    <option value="admin">Modifié par les chefs d'unité</option>
                </select>
                <div class="input-group">
                    <input type="text" class="ics-link-input" readonly value="https://example.test/calendar/feed/abc.ics">
                    <button type="button" class="copy-link-btn">Copier</button>
                </div>
                <button type="button" class="regenerate-btn" data-calendar-id="7">Régénérer</button>
                <button type="button" class="delete-calendar-btn" data-calendar-id="7">Supprimer</button>
            </div>
            <div class="border">
                <div class="input-group">
                    <input type="text" id="unit-ics-link" readonly value="https://example.test/calendar/feed/unit/xyz.ics">
                    <button type="button" id="copy-unit-link">Copier</button>
                </div>
                <button type="button" id="regenerate-unit-token">Régénérer</button>
            </div>
        </div>

        <div id="add-calendar-form">
            <input id="new-calendar-name" value="Anniversaires">
            <select id="new-calendar-visibility">
                <option value="public" selected>Public</option>
                <option value="chief">Animateur</option>
            </select>
            <button type="button" id="add-calendar-btn">Ajouter</button>
        </div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/calendar-config.js');
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

/** @param {string} selector */
function click(selector) {
    document.querySelector(selector).dispatchEvent(new Event('click'));
}

/** @param {string} selector */
function submit(selector) {
    document.querySelector(selector).dispatchEvent(new Event('submit'));
}

beforeEach(() => {
    vi.resetModules();
    vi.restoreAllMocks();
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn() } });
});

describe('calendar-config.js: entry guard', () => {
    it('does nothing at all on a page without the calendar configuration DOM', async () => {
        document.head.innerHTML = '';
        document.body.innerHTML = '<p>Une autre page</p>';
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await expect(import('../../public/assets/js/calendar-config.js')).resolves.toBeDefined();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('calendar-config.js: event defaults', () => {
    it('posts the four defaults with the CSRF token in the body AND the header', async () => {
        await boot();

        submit('#defaults-form');
        await settle();

        expect(postsTo('/config/calendar/defaults')).toEqual([{
            event_default_title: 'Réunion',
            event_default_start_time: '14:00',
            event_default_end_time: '17:00',
            event_default_location: 'Local',
            _csrf_token: 'tok',
        }]);
        expect(fetch.mock.calls[0][1].headers['X-CSRF-Token']).toBe('tok');
        expect(fetch.mock.calls[0][1].method).toBe('POST');
    });

    it('confirms the save with a success toast instead of staying silent', async () => {
        await boot();

        submit('#defaults-form');
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Valeurs par défaut enregistrées.',
            { variant: 'success' },
        );
    });

    it('toasts the server error — never the native alert() — when the save is refused', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Heure de fin invalide.' }));
        await boot();

        submit('#defaults-form');
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Heure de fin invalide.',
            { variant: 'error' },
        );
        expect(window.alert).not.toHaveBeenCalled();
    });

    it('reads an HTTP 500 HTML page as a failure, not as a success', async () => {
        // The exact conflation ScoutMagicApi exists to prevent: `ok` is
        // HTTP-level, the verdict is data.success, and a non-JSON body is
        // neither.
        global.fetch = vi.fn(() => htmlErrorResponse());
        await boot();

        submit('#defaults-form');
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Erreur : réponse serveur invalide.',
            { variant: 'error' },
        );
    });
});

describe('calendar-config.js: multi-day reminders', () => {
    it('posts the switch state and the delay', async () => {
        await boot();

        submit('#notifications-form');
        await settle();

        expect(postsTo('/config/calendar/notifications')).toEqual([
            { enabled: true, days_before: 14, _csrf_token: 'tok' },
        ]);
    });

    it('posts enabled:false when the switch is off', async () => {
        await boot();
        document.getElementById('notify-enabled').checked = false;

        submit('#notifications-form');
        await settle();

        expect(postsTo('/config/calendar/notifications')[0].enabled).toBe(false);
    });

    it('shows the server error next to the field, as text rather than markup', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)> Délai invalide.' }));
        await boot();

        submit('#notifications-form');
        await settle();

        const box = document.getElementById('notifications-error');
        expect(box.classList.contains('d-none')).toBe(false);
        expect(box.textContent).toBe('<img src=x onerror=alert(1)> Délai invalide.');
        expect(box.querySelector('img')).toBeNull();
    });

    it('hides a previous error and toasts on a successful save', async () => {
        await boot();
        const box = document.getElementById('notifications-error');
        box.classList.remove('d-none');
        box.textContent = 'Erreur précédente';

        submit('#notifications-form');
        await settle();

        expect(box.classList.contains('d-none')).toBe(true);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Rappels enregistrés.', { variant: 'success' });
    });
});

describe('calendar-config.js: visibility', () => {
    it('posts the calendar id and the new visibility of the changed select', async () => {
        await boot();
        const select = document.querySelector('#supplementary-calendar-list .visibility-select');
        select.value = 'admin';

        select.dispatchEvent(new Event('change'));
        await settle();

        expect(postsTo('/config/calendar/visibility')).toEqual([
            { calendar_id: 7, visibility: 'admin', _csrf_token: 'tok' },
        ]);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Visibilité mise à jour.', { variant: 'success' });
    });

    it('toasts the error when the server refuses the change', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Visibilité inconnue.' }));
        await boot();

        document.querySelector('.visibility-select').dispatchEvent(new Event('change'));
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Visibilité inconnue.', { variant: 'error' });
    });
});

// The second select on the same row. It is NOT a copy of the first: the
// server can refuse this one on a rule the browser does not know (a
// calendar the animateurs cannot see may not be handed to them to edit),
// so a refusal has to put the select back where it was. A select left
// showing a value the server rejected is the worst outcome here — the page
// would claim a permission that does not exist.
describe('calendar-config.js: who may modify the events', () => {
    it('posts the calendar id and the new write role of the changed select', async () => {
        await boot();
        const select = document.querySelector('#supplementary-calendar-list .edit-role-select');
        select.value = 'admin';

        select.dispatchEvent(new Event('change'));
        await settle();

        expect(postsTo('/config/calendar/edit-role')).toEqual([
            { calendar_id: 7, edit_role_min: 'admin', _csrf_token: 'tok' },
        ]);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Droit de modification mis à jour.', { variant: 'success' });
    });

    it('leaves the other select alone: the two settings are independent', async () => {
        await boot();
        const editRole = document.querySelector('#supplementary-calendar-list .edit-role-select');
        editRole.value = 'admin';

        editRole.dispatchEvent(new Event('change'));
        await settle();

        expect(postsTo('/config/calendar/visibility')).toEqual([]);
    });

    it('reverts the select and toasts the reason when the server refuses', async () => {
        global.fetch = vi.fn(() => jsonResponse({
            success: false,
            error: "Un calendrier visible par les chefs d'unité seulement ne peut pas être modifiable par les animateurs.",
        }));
        await boot();
        const select = document.querySelector('.edit-role-select');
        select.value = 'admin';

        select.dispatchEvent(new Event('change'));
        await settle();

        expect(select.value).toBe('chief');
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            "Un calendrier visible par les chefs d'unité seulement ne peut pas être modifiable par les animateurs.",
            { variant: 'error' }
        );
    });

    it('reverts to the last ACCEPTED value, not to the one the refusal replaced', async () => {
        await boot();
        const select = document.querySelector('.edit-role-select');

        // First change succeeds: 'admin' becomes the value to fall back to.
        select.value = 'admin';
        select.dispatchEvent(new Event('change'));
        await settle();

        // Second change is refused; reverting to the initial 'chief' would
        // undo a change the server DID accept.
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Refusé.' }));
        select.value = 'chief';
        select.dispatchEvent(new Event('change'));
        await settle();

        expect(select.value).toBe('admin');
    });

    it('does not read an HTML 500 page as a saved setting', async () => {
        global.fetch = vi.fn(() => htmlErrorResponse());
        await boot();
        const select = document.querySelector('.edit-role-select');
        select.value = 'admin';

        select.dispatchEvent(new Event('change'));
        await settle();

        expect(select.value).toBe('chief');
        expect(window.ScoutMagicToast.show).not.toHaveBeenCalledWith(
            'Droit de modification mis à jour.',
            { variant: 'success' }
        );
    });
});

describe('calendar-config.js: copying an ICS link', () => {
    it('copies the link of the clicked row and selects it as a fallback', async () => {
        await boot();
        const input = document.querySelector('.ics-link-input');
        const select = vi.spyOn(input, 'select');

        click('.copy-link-btn');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://example.test/calendar/feed/abc.ics');
        expect(select).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('copies the combined unit feed link', async () => {
        await boot();

        click('#copy-unit-link');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://example.test/calendar/feed/unit/xyz.ics');
    });
});

describe('calendar-config.js: regenerating one calendar token', () => {
    it('asks the shared confirmation — never the native box — with the French message and « Régénérer »', async () => {
        await boot();

        click('.regenerate-btn');
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: REGENERATE_MESSAGE,
            confirmLabel: 'Régénérer',
        });
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('posts nothing and reloads nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        click('.regenerate-btn');
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('posts the calendar id and reloads when it resolves true', async () => {
        await boot();

        click('.regenerate-btn');
        await settle();

        expect(postsTo('/config/calendar/regenerate')).toEqual([
            { calendar_id: 7, _csrf_token: 'tok' },
        ]);
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('toasts the error and keeps the page as it is when the server refuses', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Calendrier introuvable.' }));
        await boot();

        click('.regenerate-btn');
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Calendrier introuvable.', { variant: 'error' });
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('calendar-config.js: regenerating the unit feed token', () => {
    it('asks the same confirmation before invalidating the combined link', async () => {
        await boot();

        click('#regenerate-unit-token');
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: REGENERATE_MESSAGE,
            confirmLabel: 'Régénérer',
        });
    });

    it('sends nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        click('#regenerate-unit-token');
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts to the unit endpoint with the CSRF token and reloads', async () => {
        await boot();

        click('#regenerate-unit-token');
        await settle();

        expect(postsTo('/config/calendar/unit-token/regenerate')).toEqual([{ _csrf_token: 'tok' }]);
        expect(window.location.reload).toHaveBeenCalled();
    });
});

describe('calendar-config.js: deleting a supplementary calendar', () => {
    it('asks the shared confirmation with the French message and « Supprimer »', async () => {
        await boot();

        click('.delete-calendar-btn');
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Supprimer définitivement ce calendrier ?',
            confirmLabel: 'Supprimer',
        });
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('sends nothing and keeps the row when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        click('.delete-calendar-btn');
        await settle();

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('#supplementary-calendar-list [data-calendar-id="7"]')).not.toBeNull();
    });

    it('posts the delete and removes the row when it resolves true', async () => {
        await boot();

        click('.delete-calendar-btn');
        await settle();

        expect(postsTo('/config/calendar/delete')).toEqual([
            { calendar_id: 7, _csrf_token: 'tok' },
        ]);
        expect(document.querySelector('#supplementary-calendar-list [data-calendar-id="7"]')).toBeNull();
    });

    it('keeps the row on screen and toasts when the server refuses the delete', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Ce calendrier contient des évènements.' }));
        await boot();

        click('.delete-calendar-btn');
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Ce calendrier contient des évènements.',
            { variant: 'error' },
        );
        expect(document.querySelector('#supplementary-calendar-list [data-calendar-id="7"]')).not.toBeNull();
    });
});

describe('calendar-config.js: adding a calendar', () => {
    it('posts the name and visibility, then reloads', async () => {
        await boot();

        click('#add-calendar-btn');
        await settle();

        expect(postsTo('/config/calendar/add')).toEqual([
            { name: 'Anniversaires', visibility: 'public', _csrf_token: 'tok' },
        ]);
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('asks no confirmation — creating a calendar destroys nothing', async () => {
        await boot();

        click('#add-calendar-btn');
        await settle();

        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
    });

    it('toasts the error and does not reload when the name is refused', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Nom déjà utilisé.' }));
        await boot();

        click('#add-calendar-btn');
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Nom déjà utilisé.', { variant: 'error' });
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});
