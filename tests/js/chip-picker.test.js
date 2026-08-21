// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP server, no
// network. Exercises the REAL implementation in
// public/assets/js/chip-picker.js (imported below, never reimplemented
// here) — the component behind partials/chip_picker.html.twig, which
// partials/section_picker.html.twig (used by the new "Membres par section"
// core page, mode:single) is a thin mapping layer over.
//
// chip-picker.js is an IIFE that reads the DOM and calls init() at import
// time, so each test builds its DOM first and then imports the module via
// vi.resetModules() + await import() (same pattern as
// tests/js/notification-badge.test.js).
//
// jsdom never computes real layout, so chip.offsetTop is always 0 unless
// overridden — each test that exercises the truncation logic defines
// offsetTop explicitly per chip to simulate which line it would land on in
// a real browser. window.matchMedia is not implemented by jsdom either and
// is stubbed per test to control the desktop/mobile branch.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function mockMatchMedia(isDesktopViewport) {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        matches: isDesktopViewport,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
}

const ROW_HEIGHT_PX = 40;

/**
 * Builds `chipCount` chips wrapping `itemsPerRow` per line, with an
 * offsetTop getter derived from each chip's CURRENT position among its
 * siblings — real flex-wrap in a browser recomputes every chip's row the
 * same way once the DOM order changes (e.g. chip-picker.js moving a
 * selected chip to the front), so a static per-element offsetTop would
 * misrepresent that reflow; deriving it from live DOM order instead keeps
 * the mock physically consistent through a reorder, the same way a real
 * browser's layout engine would.
 */
function buildSingleModeMarkup(chipCount, itemsPerRow) {
    const chips = Array.from({ length: chipCount }, (_, i) =>
        `<a class="chip-picker-item chip-picker-chip" data-id="${i}" href="/x?section=${i}">Section ${i}</a>`
    ).join('');
    document.body.innerHTML = `
        <div class="chip-picker" data-mode="single" data-sheet-target="#sheet">
            <div class="chip-picker-chips">${chips}</div>
        </div>
        <div id="sheet"></div>
    `;
    const wrap = document.querySelector('.chip-picker-chips');
    document.querySelectorAll('.chip-picker-item').forEach((el) => {
        Object.defineProperty(el, 'offsetTop', {
            configurable: true,
            get: () => Math.floor(Array.prototype.indexOf.call(wrap.children, el) / itemsPerRow) * ROW_HEIGHT_PX,
        });
    });
}

function buildMultiModeMarkup() {
    document.body.innerHTML = `
        <div id="picker" class="chip-picker" data-mode="multi" data-sheet-target="#sheet">
            <div class="chip-picker-chips">
                <button type="button" class="chip-picker-item chip-picker-chip btn btn-outline-secondary" data-id="1" data-selected="false" aria-pressed="false">Infirmier</button>
                <button type="button" class="chip-picker-item chip-picker-chip btn btn-outline-secondary" data-id="2" data-selected="false" aria-pressed="false">Trésorier</button>
            </div>
        </div>
        <div id="sheet">
            <button type="button" class="chip-picker-item chip-picker-sheet-row" data-id="1" data-selected="false" aria-pressed="false">
                <i class="bi bi-check-lg invisible"></i> Infirmier
            </button>
        </div>
    `;
    document.querySelectorAll('.chip-picker-item').forEach((el) => {
        Object.defineProperty(el, 'offsetTop', { configurable: true, get: () => 0 });
    });
}

describe('chip-picker.js truncation (mode:single, section_picker\'s own mode)', () => {
    beforeEach(() => {
        vi.resetModules();
    });

    it('does not truncate or hide anything at the lg breakpoint and up', async () => {
        mockMatchMedia(true);
        buildSingleModeMarkup(5, 2);
        await import('../../public/assets/js/chip-picker.js');

        const chips = document.querySelectorAll('.chip-picker-item');
        chips.forEach((c) => expect(c.classList.contains('d-none')).toBe(false));
        expect(document.querySelector('.chip-picker-overflow')).toBeNull();
    });

    it('leaves chips untouched when everything already fits in 2 lines', async () => {
        mockMatchMedia(false);
        buildSingleModeMarkup(4, 2);
        await import('../../public/assets/js/chip-picker.js');

        document.querySelectorAll('.chip-picker-item').forEach((c) => expect(c.classList.contains('d-none')).toBe(false));
        expect(document.querySelector('.chip-picker-overflow')).toBeNull();
    });

    it('hides rows beyond the first two and shows a "+N" overflow chip below lg', async () => {
        mockMatchMedia(false);
        // 5 chips across 3 rows (2 + 2 + 1) — the 3rd row (1 chip) must be hidden.
        buildSingleModeMarkup(5, 2);
        await import('../../public/assets/js/chip-picker.js');

        const hidden = Array.prototype.filter.call(
            document.querySelectorAll('.chip-picker-item'),
            (c) => c.classList.contains('d-none')
        );
        expect(hidden).toHaveLength(1);
        expect(hidden[0].dataset.id).toBe('4');

        const overflow = document.querySelector('.chip-picker-overflow');
        expect(overflow).not.toBeNull();
        expect(overflow.textContent).toBe('+1');
        expect(overflow.dataset.bsTarget).toBe('#sheet');
    });

    it('never hides the selected chip, because it is moved to the front (row 0) before the cutoff is measured', async () => {
        mockMatchMedia(false);
        buildSingleModeMarkup(5, 2);
        // Whichever chip is selected, moving it to the front means it always
        // lands in row 0 — this is what actually guarantees a selection can
        // never be the thing hidden, rather than a special case in the
        // hiding logic itself.
        document.querySelectorAll('.chip-picker-item')[4].dataset.selected = 'true';

        await import('../../public/assets/js/chip-picker.js');

        const selectedChip = document.querySelector('.chip-picker-item[data-id="4"]');
        expect(selectedChip.classList.contains('d-none')).toBe(false);
    });

    it('moves the selected chip to the front of the DOM before measuring rows', async () => {
        mockMatchMedia(false);
        buildSingleModeMarkup(5, 2);
        document.querySelectorAll('.chip-picker-item')[4].dataset.selected = 'true';

        await import('../../public/assets/js/chip-picker.js');

        const firstChip = document.querySelector('.chip-picker-chips .chip-picker-item');
        expect(firstChip.dataset.id).toBe('4');
    });
});

describe('chip-picker.js selection (mode:multi)', () => {
    beforeEach(() => {
        vi.resetModules();
        mockMatchMedia(true); // desktop: truncation itself is not under test here
        buildMultiModeMarkup();
    });

    it('toggles a chip on click and dispatches chip-picker:change with the new selection', async () => {
        await import('../../public/assets/js/chip-picker.js');

        const picker = document.getElementById('picker');
        const listener = vi.fn();
        picker.addEventListener('chip-picker:change', listener);

        const chip = picker.querySelector('.chip-picker-item[data-id="1"]');
        chip.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(chip.dataset.selected).toBe('true');
        expect(chip.getAttribute('aria-pressed')).toBe('true');
        expect(chip.classList.contains('btn-primary')).toBe(true);
        expect(listener).toHaveBeenCalledTimes(1);
        expect(listener.mock.calls[0][0].detail.selectedIds).toEqual(['1']);
    });

    it('toggles back off on a second click', async () => {
        await import('../../public/assets/js/chip-picker.js');
        const picker = document.getElementById('picker');
        const chip = picker.querySelector('.chip-picker-item[data-id="1"]');

        chip.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        chip.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(chip.dataset.selected).toBe('false');
        expect(chip.getAttribute('aria-pressed')).toBe('false');
        expect(chip.classList.contains('btn-primary')).toBe(false);
    });

    it('keeps the chip and its mirrored bottom-sheet row in sync', async () => {
        await import('../../public/assets/js/chip-picker.js');
        const picker = document.getElementById('picker');
        const chip = picker.querySelector('.chip-picker-item[data-id="1"]');

        chip.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        const sheetRow = document.querySelector('#sheet .chip-picker-item[data-id="1"]');
        expect(sheetRow.dataset.selected).toBe('true');
        expect(sheetRow.querySelector('.bi-check-lg').classList.contains('invisible')).toBe(false);
    });

    it('clicking a bottom-sheet row toggles the same state as clicking the chip', async () => {
        await import('../../public/assets/js/chip-picker.js');
        const sheetRow = document.querySelector('#sheet .chip-picker-item[data-id="1"]');

        sheetRow.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        const chip = document.getElementById('picker').querySelector('.chip-picker-item[data-id="1"]');
        expect(chip.dataset.selected).toBe('true');
    });

    it('window.ChipPicker.setSelected() updates visual state without dispatching chip-picker:change', async () => {
        await import('../../public/assets/js/chip-picker.js');
        const picker = document.getElementById('picker');
        const listener = vi.fn();
        picker.addEventListener('chip-picker:change', listener);

        window.ChipPicker.setSelected('picker', '2', true);

        const chip = picker.querySelector('.chip-picker-item[data-id="2"]');
        expect(chip.dataset.selected).toBe('true');
        expect(listener).not.toHaveBeenCalled();
    });
});
