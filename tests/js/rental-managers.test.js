// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/rental-managers.js (imported below,
// never reimplemented here).
//
// What the assertions are anchored to, per docs/js-test-coverage-plan.md
// §3.2: the request URL the server owns the other half of, and the field
// NAMES the form posts — which is the whole contract between this script and
// RentalConfigController::saveManagers(). A member added through the search
// box must arrive at the server in exactly the same shape as one picked from
// the plain <select> a visitor without JavaScript uses.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/rental-managers.js';

const SEARCH_URL = '/admin/locations/gestionnaire-recherche';

function renderForm({ managers = [], candidates = [] } = {}) {
    document.body.innerHTML = `
        <form id="rental-managers-form" action="/admin/locations/managers"
              data-search-url="${SEARCH_URL}">
            <div id="rental-manager-selected">
                <p id="rental-manager-empty"${managers.length ? ' hidden' : ''}>Aucun gestionnaire désigné.</p>
                <ul class="list-group" id="rental-manager-selected-list">
                    ${managers.map((m) => `
                        <li class="list-group-item" data-member-id="${m.id}">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="manager-${m.id}"
                                       name="manager_member_ids[]" value="${m.id}" checked>
                                <label class="form-check-label" for="manager-${m.id}">${m.label}</label>
                            </div>
                        </li>`).join('')}
                </ul>
            </div>
            <div id="rental-manager-select-wrapper">
                <select id="rental-manager-select" name="manager_member_ids[]" multiple>
                    ${candidates.map((c) => `<option value="${c.id}">${c.label}</option>`).join('')}
                </select>
            </div>
            <input type="text" class="d-none" id="rental-manager-search">
            <div class="d-none" id="rental-manager-search-help"></div>
            <ul class="d-none" id="rental-manager-results"></ul>
        </form>
    `;
}

function boot() {
    document.dispatchEvent(new Event('DOMContentLoaded'));
}

/** Every value the form would actually POST, per field name. */
function postedValues(name) {
    return Array.from(document.querySelectorAll(`[name="${name}"]`))
        .filter((el) => (el.type === 'checkbox' ? el.checked : true))
        .flatMap((el) => (el.tagName === 'SELECT'
            ? Array.from(el.selectedOptions).map((o) => o.value)
            : [el.value]));
}

async function typeSearch(value) {
    const input = document.getElementById('rental-manager-search');
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(300);
}

describe('rental-managers.js', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.useFakeTimers();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve([
                { id: 42, label: 'Dupont Marie (Akéla)', sublabel: 'Louveteaux — Animateur' },
            ]),
        }));
    });

    it('does nothing and does not throw when the managers form is absent', () => {
        document.body.innerHTML = '';

        expect(() => boot()).not.toThrow();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('swaps the plain select for the search box only once everything resolved', () => {
        renderForm();
        boot();

        expect(document.getElementById('rental-manager-select-wrapper').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('rental-manager-search').classList.contains('d-none')).toBe(false);
    });

    it('leaves the plain select in place when the search URL is missing', () => {
        // Progressive enhancement: a half-rendered page must degrade to the
        // control that works without any of this.
        renderForm();
        document.getElementById('rental-managers-form').removeAttribute('data-search-url');
        boot();

        expect(document.getElementById('rental-manager-select-wrapper').classList.contains('d-none')).toBe(false);
    });

    it('does not search on a one-letter query', async () => {
        renderForm();
        boot();

        await typeSearch('d');

        expect(fetch).not.toHaveBeenCalled();
    });

    it('queries the server endpoint once the query is long enough', async () => {
        renderForm();
        boot();

        await typeSearch('dupont');

        expect(fetch).toHaveBeenCalledWith(
            SEARCH_URL + '?q=dupont',
            expect.objectContaining({ headers: expect.any(Object) })
        );
    });

    it('debounces: three keystrokes in a row produce one request', async () => {
        renderForm();
        boot();

        const input = document.getElementById('rental-manager-search');
        ['dup', 'dupo', 'dupont'].forEach((value) => {
            input.value = value;
            input.dispatchEvent(new Event('input'));
        });
        await vi.advanceTimersByTimeAsync(300);

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith(SEARCH_URL + '?q=dupont', expect.anything());
    });

    it('adds the picked member under the SAME field name the plain select uses', async () => {
        renderForm();
        boot();
        await typeSearch('dupont');

        document.querySelector('#rental-manager-results button').click();

        // The contract with saveManagers(): this exact field name, carrying
        // the member id. Nothing else about the request may differ from the
        // no-JavaScript path.
        expect(postedValues('manager_member_ids[]')).toContain('42');
        expect(document.querySelector('[data-member-id="42"]')).not.toBeNull();
    });

    it('gives the added row a renter-contact toggle, unticked', async () => {
        renderForm();
        boot();
        await typeSearch('dupont');
        document.querySelector('#rental-manager-results button').click();

        const contact = document.querySelector('[data-member-id="42"] [name="renter_contact_member_ids[]"]');
        expect(contact).not.toBeNull();
        expect(contact.checked).toBe(false);
        expect(postedValues('renter_contact_member_ids[]')).not.toContain('42');
    });

    it('never posts the same member twice: the picked option leaves the select', async () => {
        renderForm({ candidates: [{ id: 42, label: 'Dupont Marie' }] });
        boot();
        await typeSearch('dupont');

        document.querySelector('#rental-manager-results button').click();

        expect(document.querySelector('#rental-manager-select option[value="42"]')).toBeNull();
        expect(postedValues('manager_member_ids[]').filter((v) => v === '42')).toHaveLength(1);
    });

    it('ignores a member already in the list rather than duplicating the row', async () => {
        renderForm({ managers: [{ id: 42, label: 'Dupont Marie' }] });
        boot();
        await typeSearch('dupont');

        document.querySelector('#rental-manager-results button').click();

        expect(document.querySelectorAll('[data-member-id="42"]')).toHaveLength(1);
    });

    it('renders the label as text, never as markup', async () => {
        // The label comes off the wire, and a member name is free text.
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve([{ id: 7, label: '<img src=x onerror=alert(1)>', sublabel: '' }]),
        }));
        renderForm();
        boot();
        await typeSearch('img');

        expect(document.querySelector('#rental-manager-results img')).toBeNull();
        document.querySelector('#rental-manager-results button').click();
        expect(document.querySelector('[data-member-id="7"] img')).toBeNull();
    });

    it('says so rather than failing silently when nothing matches', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve([]) }));
        renderForm();
        boot();

        await typeSearch('zzz');

        const results = document.getElementById('rental-manager-results');
        expect(results.classList.contains('d-none')).toBe(false);
        expect(results.textContent).toContain('Aucun membre trouvé');
    });

    it('closes the result list on a network failure instead of leaving a stale one open', async () => {
        renderForm();
        boot();
        await typeSearch('dupont');
        expect(document.getElementById('rental-manager-results').classList.contains('d-none')).toBe(false);

        global.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));
        await typeSearch('dupond');

        expect(document.getElementById('rental-manager-results').classList.contains('d-none')).toBe(true);
    });

    it('hides the empty-state line once a manager is added', async () => {
        renderForm();
        boot();
        expect(document.getElementById('rental-manager-empty').hidden).toBe(false);

        await typeSearch('dupont');
        document.querySelector('#rental-manager-results button').click();

        expect(document.getElementById('rental-manager-empty').hidden).toBe(true);
    });
});
