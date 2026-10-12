// Isolated JavaScript unit test — jsdom only, fetch and the toast mocked.
// Exercises the REAL public/assets/js/registration-capacity-settings.js
// (imported below, never reimplemented here) on the markup of
// Formulaire d'inscription › Réglages (modules/registration/views/config/
// _settings_capacities.html.twig and _settings_priorities.html.twig,
// issue #843 IT-03).
//
// What is pinned: the arithmetic of the thresholds (a draft committed on
// leaving the field, the two handles that never cross, the worked example
// and its agreements, the empty « Peut-être » band); the slider and the
// fields kept in step; the waitlist switch saved on change, greying the
// thresholds out and putting itself back when refused; and what this
// module adds to the shared connected lists — « Ordre d'arrivée » refused
// in « Toujours Oui », the menu that moves a criterion on a small screen,
// and the field names the form sends. The connected lists themselves are
// list-editor.js's, tested by tests/js/list-editor*.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function thresholdsBox({ yes = 70, maybe = 100, disabled = false } = {}) {
    return `<form method="post" action="/config/inscriptions/seuils" data-threshold-box>
        <fieldset class="${disabled ? 'opacity-50' : ''}" ${disabled ? 'disabled' : ''}>
            <p class="${disabled ? '' : 'd-none'}" data-threshold-disabled-note>Désactivés…</p>
            <div data-dual-slider>
                <span data-slider-zone="yes"></span><span data-slider-zone="maybe"></span><span data-slider-zone="no"></span>
                <input type="range" data-slider-handle="yes" min="1" max="120" value="${yes}">
                <input type="range" data-slider-handle="maybe" min="1" max="120" value="${maybe}">
            </div>
            <span data-slider-label="yes"></span><span data-slider-label="maybe"></span><span data-slider-label="no"></span>
            <input type="number" id="threshold_yes_percent" name="threshold_yes_percent" value="${yes}" data-threshold-field="yes">
            <input type="number" id="threshold_maybe_percent" name="threshold_maybe_percent" value="${maybe}" data-threshold-field="maybe">
            <p class="${maybe > 100 ? '' : 'd-none'}" data-threshold-over-full>Au-delà de 100 %…</p>
            <input type="number" id="threshold_example_capacity" value="15" data-example-capacity>
            <ul data-threshold-example></ul>
            <button type="submit">Enregistrer</button>
        </fieldset>
    </form>`;
}

const SWITCH = '<input type="checkbox" id="waitlist_enabled" checked data-waitlist-switch>';

function priorityRow(zone, id, canAlways = true) {
    return `<div class="list-editor-item" draggable="true" data-id="${id}">
        <button type="button" class="list-editor-move-up"></button>
        <button type="button" class="list-editor-move-down"></button>
        <div><input type="hidden" name="priority[${zone}][]" value="${id}" data-priority-input data-can-always="${canAlways ? '1' : '0'}"></div>
        <select data-priority-move>
            <option value="always"${zone === 'always' ? ' selected' : ''}>Toujours Oui</option>
            <option value="rank"${zone === 'rank' ? ' selected' : ''}>Classement</option>
            <option value="off"${zone === 'off' ? ' selected' : ''}>Désactivé</option>
        </select>
    </div>`;
}

function zone(key, rows, empty) {
    return `<div data-priority-zone="${key}"${key === 'off' ? ' data-touch-empty-label="Choisissez « Désactivé » dans le menu d\'un critère pour le désactiver."' : ''}>
        <div class="list-editor" id="priority-${key}" data-reorder-url="" data-sortable-group="registration-priorities" data-group-key="${key}">
            <div class="list-editor-items">${rows.join('')}<p class="list-editor-empty${rows.length ? ' d-none' : ''}">${empty}</p></div>
        </div>
    </div>`;
}

const RANK_ONLY = "« Ordre d'arrivée de la demande » ne peut pas être « Toujours Oui » : elle ne sert qu'à classer.";

const PRIORITIES = `<form method="post" action="/config/inscriptions/priorites" data-priority-form
        data-rank-only-message="${RANK_ONLY}">
    <span data-waitlist-off-mention class="d-none"> (sans effet…)</span>
    ${zone('always', [priorityRow('always', 'sibling')], 'Aucune règle : toutes les demandes sont classées.')}
    ${zone('rank', [priorityRow('rank', 'previous_no_room'), priorityRow('rank', 'arrival', false)], 'Aucun critère.')}
    ${zone('off', [], 'Glissez un critère ici pour le désactiver.')}
    <div class="d-none" data-priority-notice></div>
    <button type="submit">Enregistrer</button>
</form>`;

function jsonResponse(data, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
}

async function boot({ wide = true } = {}) {
    vi.resetModules();
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    window.matchMedia = vi.fn(() => ({ matches: wide }));
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/sortable.js');
    await import('../../public/assets/js/list-editor.js');
    await import('../../public/assets/js/registration-capacity-settings.js');
    return window.ScoutMagicRegistrationSettings;
}

const $ = (selector) => document.querySelector(selector);
const fields = () => [...document.querySelectorAll('[data-priority-input]')].map((input) => [input.name, input.value]);
const zoneIds = (key) => [...document.querySelectorAll(`#priority-${key} .list-editor-item`)].map((row) => row.dataset.id);

/** Types $value in a field and leaves it. */
function typeAndLeave(field, value) {
    field.value = value;
    field.dispatchEvent(new Event('blur'));
}

describe('registration-capacity-settings.js — the arithmetic', () => {
    let settings;

    beforeEach(async () => {
        document.body.innerHTML = '';
        window.ScoutMagicToast = { show: vi.fn() };
        settings = await boot();
    });

    it('commits a draft rounded and bounded, and gives the last value back for an empty or invalid one', () => {
        expect(settings.commitNumber('70.6', 50, 1, 119)).toBe(71);
        expect(settings.commitNumber(' 0 ', 50, 1, 119)).toBe(1);
        expect(settings.commitNumber('130', 50, 1, 119)).toBe(119);
        expect(settings.commitNumber('', 50, 1, 119)).toBe(50);
        expect(settings.commitNumber('soixante', 50, 1, 119)).toBe(50);
    });

    it('never lets one handle cross the other, and keeps both within 1–120', () => {
        expect(settings.movePair('yes', 105, { yes: 70, maybe: 100 })).toEqual({ yes: 99, maybe: 100 });
        expect(settings.movePair('maybe', 40, { yes: 70, maybe: 100 })).toEqual({ yes: 70, maybe: 71 });
        expect(settings.movePair('yes', 0, { yes: 70, maybe: 100 })).toEqual({ yes: 1, maybe: 100 });
        expect(settings.movePair('maybe', 140, { yes: 70, maybe: 100 })).toEqual({ yes: 70, maybe: 120 });
    });

    it('measures the zones on the track a handle travels, 1 to 120 unless told otherwise', () => {
        const geometry = settings.sliderGeometry({ yes: 60, maybe: 108 });
        expect(geometry.yes).toBeCloseTo(59 / 119);
        expect(geometry.maybe).toBeCloseTo(48 / 119);
        expect(geometry.no).toBeCloseTo(12 / 119);
        expect(geometry.full).toBeCloseTo(99 / 119);
        expect(geometry.yes + geometry.maybe + geometry.no).toBeCloseTo(1);

        expect(settings.sliderGeometry({ yes: 50, maybe: 100 }, { min: 0, max: 200 }).full).toBeCloseTo(0.5);
        expect(settings.movePair('maybe', 190, { yes: 50, maybe: 100 }, { min: 0, max: 150 })).toEqual({ yes: 50, maybe: 150 });
    });

    it('writes a length along the bar with half a thumb at each edge, so a handle sits on its boundary', () => {
        expect(settings.barLength(69 / 119, true)).toBe('calc(0.75rem + 0.57983 * (100% - 1.5rem))');
        expect(settings.barLength(30 / 119, false)).toBe('calc(0.2521 * (100% - 1.5rem))');
    });

    it('counts the places within a percentage with the bound included', () => {
        expect(settings.maxOccupiedFor(15, 70)).toBe(10);
        expect(settings.maxOccupiedFor(10, 70)).toBe(7);
        expect(settings.maxOccupiedFor(15, 100)).toBe(15);
        expect(settings.maxOccupiedFor(15, 120)).toBe(18);
    });

    it('writes the default example: 15 places, 70 and 100 %', () => {
        expect(settings.exampleLines(15, { yes: 70, maybe: 100 })).toEqual([
            { level: 'yes', before: "tant qu'il y a ", strong: 'au plus 10 places déjà occupées', after: '' },
            { level: 'maybe', before: 'quand il y a ', strong: 'de 11 à 15 places déjà occupées', after: '' },
            { level: 'no', before: 'à partir de ', strong: '16 places déjà occupées', after: ' (1 de plus que la capacité)' },
        ]);
    });

    it('agrees « 1 place déjà occupée » and « 0 place »', () => {
        const [yes, maybe, no] = settings.exampleLines(1, { yes: 70, maybe: 100 });
        expect(yes.strong).toBe('0 place déjà occupée');
        expect(maybe.strong).toBe('1 place déjà occupée');
        expect(no.strong).toBe('2 places déjà occupées');
    });

    it('says plainly when the « Peut-être » band holds no number of places', () => {
        const maybe = settings.exampleLines(2, { yes: 50, maybe: 60 })[1];
        expect(maybe).toEqual({
            level: 'maybe',
            before: '',
            strong: 'jamais avec cette capacité : les deux limites tombent sur le même nombre de places',
            after: '',
        });
    });

    it('counts the free places left under Non, or says the year is full', () => {
        expect(settings.exampleLines(15, { yes: 50, maybe: 80 })[2].after).toBe(' (2 places libres ou moins)');
        expect(settings.exampleLines(10, { yes: 50, maybe: 80 })[2].after).toBe(' (1 place libre ou moins)');
        expect(settings.exampleLines(10, { yes: 50, maybe: 90 })[2].after).toBe(" (l'année est complète)");
    });
});

describe('registration-capacity-settings.js — the thresholds sub-box', () => {
    beforeEach(() => {
        window.ScoutMagicToast = { show: vi.fn() };
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
    });

    it('renders the worked example and the zones on load, as text', async () => {
        document.body.innerHTML = thresholdsBox();
        await boot();

        const lines = [...document.querySelectorAll('[data-threshold-example] li')].map((li) => li.textContent);
        expect(lines).toEqual([
            "Ouitant qu'il y a au plus 10 places déjà occupées",
            'Peut-êtrequand il y a de 11 à 15 places déjà occupées',
            'Nonà partir de 16 places déjà occupées (1 de plus que la capacité)',
        ]);
        expect($('[data-slider-zone="yes"]').style.width).toBe('calc(0.75rem + 0.57983 * (100% - 1.5rem))');
        expect($('[data-slider-label="no"]').style.width).toBe('calc(0.75rem + 0.16807 * (100% - 1.5rem))');
    });

    it('moves the field with its handle, and stops a handle at the other one', async () => {
        document.body.innerHTML = thresholdsBox();
        await boot();

        const yesHandle = $('[data-slider-handle="yes"]');
        yesHandle.value = '110';
        yesHandle.dispatchEvent(new Event('input'));

        expect($('#threshold_yes_percent').value).toBe('99');
        expect(yesHandle.value).toBe('99');
        expect($('[data-threshold-example] li').textContent).toContain('au plus 14 places');
    });

    it('keeps a typed value as a draft until the field is left, then moves the handle', async () => {
        document.body.innerHTML = thresholdsBox();
        await boot();

        const maybeField = $('#threshold_maybe_percent');
        maybeField.value = '11';
        maybeField.dispatchEvent(new Event('input'));
        expect($('[data-slider-handle="maybe"]').value).toBe('100');

        typeAndLeave(maybeField, '110');
        expect($('[data-slider-handle="maybe"]').value).toBe('110');
        expect($('[data-threshold-over-full]').classList.contains('d-none')).toBe(false);

        typeAndLeave(maybeField, '50');
        expect(maybeField.value).toBe('71');
        expect($('[data-threshold-over-full]').classList.contains('d-none')).toBe(true);
    });

    it('ignores an emptied field, and commits on Enter without sending the form', async () => {
        document.body.innerHTML = thresholdsBox();
        await boot();

        const yesField = $('#threshold_yes_percent');
        typeAndLeave(yesField, '');
        expect(yesField.value).toBe('70');

        yesField.value = '80';
        const enter = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
        yesField.dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(true);
        expect($('[data-slider-handle="yes"]').value).toBe('80');
    });

    it('recomputes the example for another capacity', async () => {
        document.body.innerHTML = thresholdsBox();
        await boot();

        typeAndLeave($('#threshold_example_capacity'), '1');

        expect($('[data-threshold-example] li').textContent).toBe("Ouitant qu'il y a 0 place déjà occupée");
    });
});

describe('registration-capacity-settings.js — « Gérer les listes d\'attente »', () => {
    beforeEach(() => {
        window.ScoutMagicToast = { show: vi.fn() };
    });

    it('saves on change and greys the thresholds out, then back in', async () => {
        document.body.innerHTML = SWITCH + thresholdsBox() + PRIORITIES;
        global.fetch = vi.fn(() => jsonResponse({ success: true, message: 'Gestion des listes d\'attente désactivée.' }));
        await boot();

        const toggle = $('#waitlist_enabled');
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

        const [url, options] = fetch.mock.calls[0];
        expect(url).toBe('/config/inscriptions/listes-attente');
        expect(JSON.parse(options.body)).toEqual({ enabled: false, _csrf_token: 'tok' });
        expect($('[data-threshold-box] fieldset').disabled).toBe(true);
        expect($('[data-threshold-disabled-note]').classList.contains('d-none')).toBe(false);
        expect($('[data-waitlist-off-mention]').classList.contains('d-none')).toBe(false);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Gestion des listes d\'attente désactivée.', { variant: 'success' });

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect($('[data-threshold-box] fieldset').disabled).toBe(false));
        expect($('[data-waitlist-off-mention]').classList.contains('d-none')).toBe(true);
    });

    it('tells the « Décisions automatiques » sub-box once the server agreed, and only then', async () => {
        document.body.innerHTML = SWITCH + thresholdsBox();
        const heard = vi.fn();
        document.addEventListener('registration:waitlist-changed', (event) => heard(event.detail));
        global.fetch = vi.fn()
            .mockImplementationOnce(() => jsonResponse({ success: false, error: 'Votre session a expiré.' }, 403))
            .mockImplementation(() => jsonResponse({ success: true, message: 'Enregistré.' }));
        await boot();

        const toggle = $('#waitlist_enabled');
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledTimes(1));
        expect(heard).not.toHaveBeenCalled();

        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(heard).toHaveBeenCalledWith({ enabled: false }));
    });

    it('puts the switch back and changes nothing when the save is refused', async () => {
        document.body.innerHTML = SWITCH + thresholdsBox();
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Votre session a expiré.' }, 403));
        await boot();

        const toggle = $('#waitlist_enabled');
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

        expect(toggle.checked).toBe(true);
        expect(toggle.disabled).toBe(false);
        expect($('[data-threshold-box] fieldset').disabled).toBe(false);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Votre session a expiré.', { variant: 'error' });
    });
});

describe('registration-capacity-settings.js — « Priorités »', () => {
    beforeEach(() => {
        vi.useRealTimers();
        window.ScoutMagicToast = { show: vi.fn() };
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
    });

    it('refuses « Ordre d\'arrivée » in « Toujours Oui » from the menu, with the mock-up\'s sentence', async () => {
        document.body.innerHTML = PRIORITIES;
        await boot({ wide: false });

        const menu = document.querySelector('[data-id="arrival"] [data-priority-move]');
        menu.value = 'always';
        menu.dispatchEvent(new Event('change', { bubbles: true }));

        expect(zoneIds('always')).toEqual(['sibling']);
        expect(zoneIds('rank')).toEqual(['previous_no_room', 'arrival']);
        expect(menu.value).toBe('rank');
        expect($('[data-priority-notice]').textContent).toBe(RANK_ONLY);
        expect($('[data-priority-notice]').classList.contains('d-none')).toBe(false);
    });

    it('moves a criterion to another zone from the menu and sends it under that zone', async () => {
        document.body.innerHTML = PRIORITIES;
        await boot({ wide: false });
        expect($('#priority-off .list-editor-empty').textContent).toBe(
            "Choisissez « Désactivé » dans le menu d'un critère pour le désactiver."
        );

        const menu = document.querySelector('[data-id="sibling"] [data-priority-move]');
        menu.value = 'off';
        menu.dispatchEvent(new Event('change', { bubbles: true }));

        expect(zoneIds('off')).toEqual(['sibling']);
        expect($('#priority-always .list-editor-empty').classList.contains('d-none')).toBe(false);
        expect($('#priority-off .list-editor-empty').classList.contains('d-none')).toBe(true);
        expect(fields()).toEqual([
            ['priority[rank][]', 'previous_no_room'],
            ['priority[rank][]', 'arrival'],
            ['priority[off][]', 'sibling'],
        ]);
        expect($('[data-priority-notice]').classList.contains('d-none')).toBe(true);
    });

    it('puts « Ordre d\'arrivée » back where it came from when it is dragged into « Toujours Oui »', async () => {
        document.body.innerHTML = PRIORITIES;
        await boot();
        expect($('#priority-off .list-editor-empty').textContent).toBe('Glissez un critère ici pour le désactiver.');

        const arrival = document.querySelector('[data-id="arrival"]');
        arrival.dispatchEvent(new Event('dragstart', { bubbles: true }));
        const always = $('#priority-always .list-editor-items');
        always.insertBefore(arrival, always.querySelector('.list-editor-empty'));
        always.dispatchEvent(new Event('drop', { bubbles: true, cancelable: true }));
        arrival.dispatchEvent(new Event('dragend', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(zoneIds('always')).toEqual(['sibling']);
        expect(zoneIds('rank')).toEqual(['previous_no_room', 'arrival']);
        expect($('[data-priority-notice]').textContent).toBe(RANK_ONLY);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('sends each criterion under the zone it was dragged to, in the order shown', async () => {
        document.body.innerHTML = PRIORITIES;
        await boot();

        const previous = document.querySelector('[data-id="previous_no_room"]');
        previous.dispatchEvent(new Event('dragstart', { bubbles: true }));
        const always = $('#priority-always .list-editor-items');
        always.insertBefore(previous, always.firstChild);
        always.dispatchEvent(new Event('drop', { bubbles: true, cancelable: true }));
        previous.dispatchEvent(new Event('dragend', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));
        $('[data-priority-form]').dispatchEvent(new Event('submit', { cancelable: true }));

        expect(fields()).toEqual([
            ['priority[always][]', 'previous_no_room'],
            ['priority[always][]', 'sibling'],
            ['priority[rank][]', 'arrival'],
        ]);
        expect($('[data-priority-notice]').classList.contains('d-none')).toBe(true);
        // No save endpoint: the box's own button saves, never a drop.
        expect(fetch).not.toHaveBeenCalled();
    });
});
