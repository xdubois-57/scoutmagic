// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP server,
// no network. Exercises the REAL implementation in
// public/assets/js/select-bar.js (imported below, never reimplemented
// here) — the component behind partials/select_bar.html.twig.
//
// select-bar.js is an IIFE that reads the DOM and calls init() at import
// time, so each test builds its DOM first and then imports the module via
// vi.resetModules() + await import() (the tests/js/staffs.test.js
// pattern).
//
// The markup below mirrors what select_bar.html.twig actually renders —
// SelectBarRenderingTest pins the server side of the same contract.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function multiModeMarkup() {
    document.body.innerHTML = `
        <div class="select-bar" id="badge-picker-77" data-mode="multi">
            <details class="select-bar-details">
                <summary class="select-bar-trigger tap-target">
                    <span>
                        <span class="d-block">Badges</span>
                        <span class="d-block select-bar-value"
                              data-none-text="Aucun badge"
                              data-count-label="badges">Aucun badge</span>
                    </span>
                    <i class="bi bi-chevron-down select-bar-chevron"></i>
                </summary>
                <div class="select-bar-panel">
                    <ul>
                        <li><button type="button" class="select-bar-item" data-id="1" data-selected="false" aria-pressed="false">
                            <span><i class="bi bi-check-lg invisible"></i></span>
                            <span><span class="select-bar-row-label">Infirmier</span></span>
                        </button></li>
                        <li><button type="button" class="select-bar-item" data-id="2" data-selected="false" aria-pressed="false">
                            <span><i class="bi bi-check-lg invisible"></i></span>
                            <span><span class="select-bar-row-label">Trésorier</span></span>
                        </button></li>
                    </ul>
                </div>
            </details>
        </div>
    `;
}

function singleModeMarkup() {
    document.body.innerHTML = `
        <div class="select-bar" id="section-picker" data-mode="single">
            <details class="select-bar-details">
                <summary class="select-bar-trigger tap-target">
                    <span class="select-bar-value" data-none-text="Choisir…" data-count-label="éléments">Louveteaux</span>
                </summary>
                <div class="select-bar-panel">
                    <ul>
                        <li><a class="select-bar-item" href="/chefs/staffs?section=1" data-id="1" data-selected="true" aria-current="true">
                            <span class="select-bar-row-label">Louveteaux</span>
                        </a></li>
                        <li><a class="select-bar-item" href="/chefs/staffs?section=2" data-id="2" data-selected="false">
                            <span class="select-bar-row-label">Éclaireurs</span>
                        </a></li>
                    </ul>
                </div>
            </details>
        </div>
    `;
}

const load = () => import('../../public/assets/js/select-bar.js');

describe('select-bar.js selection (mode:multi)', () => {
    beforeEach(() => {
        vi.resetModules();
        multiModeMarkup();
    });

    it('toggles a row on click and dispatches select-bar:change with the new selection', async () => {
        await load();
        const bar = document.getElementById('badge-picker-77');
        const listener = vi.fn();
        bar.addEventListener('select-bar:change', listener);

        bar.querySelector('.select-bar-item[data-id="1"]').click();

        expect(listener).toHaveBeenCalledTimes(1);
        expect(listener.mock.calls[0][0].detail).toEqual({ selectedIds: ['1'] });
    });

    it('reflects the toggle in data-selected, aria-pressed and the check icon', async () => {
        await load();
        const row = document.querySelector('.select-bar-item[data-id="1"]');

        row.click();

        expect(row.dataset.selected).toBe('true');
        expect(row.getAttribute('aria-pressed')).toBe('true');
        expect(row.querySelector('.bi-check-lg').classList.contains('invisible')).toBe(false);
    });

    it('toggles back off on a second click', async () => {
        await load();
        const bar = document.getElementById('badge-picker-77');
        const row = bar.querySelector('.select-bar-item[data-id="1"]');
        const listener = vi.fn();
        bar.addEventListener('select-bar:change', listener);

        row.click();
        row.click();

        expect(row.dataset.selected).toBe('false');
        expect(listener.mock.calls[1][0].detail).toEqual({ selectedIds: [] });
    });

    it('summarises the selection on the trigger — none, one, then a count', async () => {
        await load();
        const value = document.querySelector('.select-bar-value');

        expect(value.textContent).toBe('Aucun badge');

        document.querySelector('.select-bar-item[data-id="1"]').click();
        expect(value.textContent).toBe('Infirmier');

        document.querySelector('.select-bar-item[data-id="2"]').click();
        expect(value.textContent).toBe('2 badges');

        document.querySelector('.select-bar-item[data-id="1"]').click();
        expect(value.textContent).toBe('Trésorier');
    });

    it('keeps the panel open across toggles — native <details>, nothing to implement', async () => {
        await load();
        const details = document.querySelector('details');
        details.open = true;

        document.querySelector('.select-bar-item[data-id="1"]').click();

        expect(details.open).toBe(true);
    });

    it('window.SelectBar.setSelected() updates the row and the summary without dispatching', async () => {
        await load();
        const bar = document.getElementById('badge-picker-77');
        const listener = vi.fn();
        bar.addEventListener('select-bar:change', listener);

        window.SelectBar.setSelected('badge-picker-77', '2', true);

        const row = bar.querySelector('.select-bar-item[data-id="2"]');
        expect(row.dataset.selected).toBe('true');
        expect(document.querySelector('.select-bar-value').textContent).toBe('Trésorier');
        // Re-dispatching would loop straight back into the caller's own
        // select-bar:change listener, which is the whole reason this
        // escape hatch exists.
        expect(listener).not.toHaveBeenCalled();
    });

    it('window.SelectBar.setSelected() is inert for an unknown picker id', async () => {
        await load();

        expect(() => window.SelectBar.setSelected('no-such-picker', '1', true)).not.toThrow();
    });
});

describe('select-bar.js panel dismissal', () => {
    beforeEach(() => {
        vi.resetModules();
        multiModeMarkup();
    });

    it('closes an open panel on a click outside it', async () => {
        await load();
        const details = document.querySelector('details');
        details.open = true;

        document.body.click();

        expect(details.open).toBe(false);
    });

    it('leaves the panel open on a click inside it', async () => {
        await load();
        const details = document.querySelector('details');
        details.open = true;

        document.querySelector('.select-bar-panel').click();

        expect(details.open).toBe(true);
    });

    it('closes an open panel on Escape', async () => {
        await load();
        const details = document.querySelector('details');
        details.open = true;

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(details.open).toBe(false);
    });

    it('ignores keys other than Escape', async () => {
        await load();
        const details = document.querySelector('details');
        details.open = true;

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));

        expect(details.open).toBe(true);
    });

    it('returns focus to the trigger when Escape closes a panel focus was inside', async () => {
        await load();
        const details = document.querySelector('details');
        const summary = details.querySelector('summary');
        details.open = true;
        details.querySelector('.select-bar-item').focus();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(document.activeElement).toBe(summary);
    });
});

describe('select-bar.js (mode:single)', () => {
    beforeEach(() => {
        vi.resetModules();
        singleModeMarkup();
    });

    it('never intercepts a row: selection is the link itself, so no JS is involved', async () => {
        await load();
        const bar = document.getElementById('section-picker');
        const listener = vi.fn();
        bar.addEventListener('select-bar:change', listener);

        const row = bar.querySelector('.select-bar-item[data-id="2"]');
        // jsdom implements no navigation; without this it logs a
        // "Not implemented: navigation" error for the real <a href>.
        row.addEventListener('click', (e) => e.preventDefault());
        row.click();

        expect(listener).not.toHaveBeenCalled();
        // The row is untouched — the browser follows its href.
        expect(row.dataset.selected).toBe('false');
        expect(row.getAttribute('href')).toBe('/chefs/staffs?section=2');
    });
});
