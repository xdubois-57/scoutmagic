// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/attestations-verification.js (imported below, never
// reimplemented): the file is an IIFE that installs
// window.ScoutMagicAttestationsVerification at import time.
//
// What is covered here is the class of bug that publishes a batch nobody
// can audit afterwards. A certificate is readable by its family and by
// nobody else once it is out (files.owner_member_id), so a row silently
// dropped by the filter, or a bulk command that touched more rows than its
// label claimed, is a family that never gets their document and nothing
// anywhere reporting it.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadVerification() {
    vi.resetModules();
    await import('../../public/assets/js/attestations-verification.js');
    return window.ScoutMagicAttestationsVerification;
}

/**
 * One row per entry: [id, function, alwaysVisible, checked, disabled].
 */
function renderRows(rows) {
    document.body.innerHTML = `
        <div id="attestations-function-filter" class="select-bar" data-mode="multi"></div>
        <p id="attestations-visible-count" data-total="${rows.length}"></p>
        <button type="button" id="attestations-toggle-visible"
                data-select-label="Sélectionner les {n} lignes affichées"
                data-deselect-label="Désélectionner les {n} lignes affichées"></button>
        <div id="attestations-lines">
            ${rows.map(([id, fn, always, checked, disabled]) => `
                <div class="attestations-line" data-line-id="${id}"
                     data-function="${fn}" data-always-visible="${always}">
                    <input type="checkbox" class="attestations-line-check" name="line_ids[]"
                           value="${id}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                </div>`).join('')}
        </div>
        <div id="attestations-selected-count"></div>`;
}

/** The shape the deposit screen produces: two Scouts, one animateur, one unresolved. */
function renderStandardBatch() {
    renderRows([
        [1, 'Scout', 0, true, false],
        [2, 'Scout', 0, true, false],
        [3, "Animateur d'unité", 0, true, false],
        [4, '', 1, false, true],
    ]);
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('filtering', () => {
    it('hides rows instead of removing them, so a filtered row keeps its state', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));

        // Untick the animateur, then filter on Scout — the classic sequence
        // that silently erased the decision when the filter removed nodes.
        rows[2].querySelector('input').checked = false;
        api.applyFilter(rows, ['Scout']);

        expect(document.querySelectorAll('.attestations-line').length).toBe(4);
        expect(rows[2].classList.contains('d-none')).toBe(true);
        expect(rows[2].querySelector('input').checked).toBe(false);

        // And it comes back, still unticked, when the filter is cleared.
        api.applyFilter(rows, []);
        expect(rows[2].classList.contains('d-none')).toBe(false);
        expect(rows[2].querySelector('input').checked).toBe(false);
    });

    it('shows every row when nothing is filtered', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));

        expect(api.applyFilter(rows, [])).toBe(4);
    });

    it('never hides a row that still needs somebody to decide something', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));

        // A row with no member has no function, so any filter would take it
        // away — and it is exactly the row somebody has to deal with.
        api.applyFilter(rows, ['Scout']);
        expect(rows[3].classList.contains('d-none')).toBe(false);

        api.applyFilter(rows, ["Animateur d'unité"]);
        expect(rows[3].classList.contains('d-none')).toBe(false);
    });

    it('filters on several functions at once', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));

        expect(api.applyFilter(rows, ['Scout', "Animateur d'unité"])).toBe(4);
    });
});

describe('the bulk command', () => {
    it('names the number of rows it will touch, not the size of the batch', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));
        const button = document.getElementById('attestations-toggle-visible');

        api.applyFilter(rows, ['Scout']);
        api.refreshToggleLabel(button, rows);

        // Two Scouts are tickable and shown; the unresolved row is shown but
        // not tickable, and the animateur is hidden.
        expect(button.textContent).toBe('Désélectionner les 2 lignes affichées');
    });

    it('acts only on the rows that are shown', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));

        api.applyFilter(rows, ['Scout']);
        api.toggleVisible(rows);

        expect(rows[0].querySelector('input').checked).toBe(false);
        expect(rows[1].querySelector('input').checked).toBe(false);
        // Hidden, and therefore untouched — the whole point.
        expect(rows[2].querySelector('input').checked).toBe(true);
    });

    it('offers to select when the shown rows are not all ticked', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));
        const button = document.getElementById('attestations-toggle-visible');

        rows[0].querySelector('input').checked = false;
        api.refreshToggleLabel(button, rows);

        expect(button.textContent).toBe('Sélectionner les 3 lignes affichées');
    });

    it('never ticks a row that has no destination', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));

        api.toggleVisible(rows);
        api.toggleVisible(rows);

        expect(rows[3].querySelector('input').checked).toBe(false);
    });

    it('is disabled when the filter leaves nothing tickable on screen', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));
        const button = document.getElementById('attestations-toggle-visible');

        api.applyFilter(rows, ['Fonction que personne ne porte']);
        api.refreshToggleLabel(button, rows);

        expect(button.hasAttribute('disabled')).toBe(true);
    });
});

describe('the two counters', () => {
    it('answers two different questions, and only one of them decides', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));
        const visible = document.getElementById('attestations-visible-count');
        const selected = document.getElementById('attestations-selected-count');

        api.applyFilter(rows, ['Scout']);
        api.refreshCounters(rows, visible, selected);

        // Three on screen (two Scouts plus the unresolved row)…
        expect(visible.textContent).toBe('3 affichées sur 4');
        // …and three still going out, because filtering changes nothing
        // about what the batch will hold.
        expect(selected.textContent).toBe('3');
    });

    it('follows the ticks rather than the filter', async () => {
        const api = await loadVerification();
        renderStandardBatch();
        const rows = api.rowsOf(document.getElementById('attestations-lines'));
        const selected = document.getElementById('attestations-selected-count');

        rows[0].querySelector('input').checked = false;
        api.refreshCounters(rows, null, selected);

        expect(selected.textContent).toBe('2');
    });

    it('agrees in number and gender when one row is shown', async () => {
        const api = await loadVerification();
        renderRows([[1, 'Scout', 0, true, false]]);
        const rows = api.rowsOf(document.getElementById('attestations-lines'));
        const visible = document.getElementById('attestations-visible-count');

        api.refreshCounters(rows, visible, null);

        expect(visible.textContent).toBe('1 affichée sur 1');
    });
});

describe('wiring', () => {
    it('re-filters and recounts when the select bar announces a change', async () => {
        await loadVerification();
        renderStandardBatch();

        // The script binds on import; re-import it now that the DOM exists.
        vi.resetModules();
        await import('../../public/assets/js/attestations-verification.js');

        document.getElementById('attestations-function-filter').dispatchEvent(
            new CustomEvent('select-bar:change', { detail: { selectedIds: ['Scout'] } })
        );

        const rows = document.querySelectorAll('.attestations-line');
        expect(rows[2].classList.contains('d-none')).toBe(true);
        expect(document.getElementById('attestations-visible-count').textContent)
            .toBe('3 affichées sur 4');
    });

    it('recounts when a checkbox is ticked', async () => {
        await loadVerification();
        renderStandardBatch();
        vi.resetModules();
        await import('../../public/assets/js/attestations-verification.js');

        const box = document.querySelector('.attestations-line-check');
        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));

        expect(document.getElementById('attestations-selected-count').textContent).toBe('2');
    });
});
