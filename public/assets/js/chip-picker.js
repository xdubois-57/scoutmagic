/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Generic chip picker — see core/View/templates/partials/chip_picker.html.twig.
// Knows nothing about what an item "is"; owns two things only:
//
// 1. Progressive-enhancement truncation to 2 lines below the `lg`
//    breakpoint (chips already wrap fully with no cap at `lg` and up,
//    matching this site's existing desktop picker behavior — see the
//    partial's own docblock). Line membership is measured from each
//    chip's real offsetTop after render, never a hardcoded chip count,
//    and recomputed on resize/orientationchange. Selected chips are
//    always moved to the front before measuring, so a selection is never
//    the one thing truncated away; if the selected chips alone exceed 2
//    lines, the cutoff extends to include all of them rather than hide
//    part of the current selection.
// 2. mode:multi selection — toggling a chip or a bottom-sheet row (the
//    same handler either way, so there is exactly one selection path)
//    and dispatching a `chip-picker:change` CustomEvent (detail:
//    { selectedIds }) on the picker container so the caller can persist
//    the choice however it needs to. This script never persists
//    anything itself.
//
// mode:single needs none of the above for selection itself — chips and
// sheet rows are plain <a href> to the exact same URL, so a plain click
// (or a screen reader, or JS failing to load at all) already works.
(function () {
    var LG_BREAKPOINT_PX = 992; // Bootstrap's $grid-breakpoints "lg"

    function isDesktop() {
        return window.matchMedia('(min-width: ' + LG_BREAKPOINT_PX + 'px)').matches;
    }

    function rowsFor(chips) {
        var rows = [];
        var rowTops = [];
        chips.forEach(function (chip) {
            var top = chip.offsetTop;
            var rowIndex = -1;
            for (var i = 0; i < rowTops.length; i++) {
                if (Math.abs(rowTops[i] - top) < 2) {
                    rowIndex = i;
                    break;
                }
            }
            if (rowIndex === -1) {
                rowTops.push(top);
                rows.push([chip]);
            } else {
                rows[rowIndex].push(chip);
            }
        });
        return rows;
    }

    function truncate(container) {
        var wrap = container.querySelector('.chip-picker-chips');
        if (!wrap) return;

        var existingOverflow = wrap.querySelector('.chip-picker-overflow');
        if (existingOverflow) existingOverflow.remove();

        var chips = Array.prototype.slice.call(wrap.querySelectorAll('.chip-picker-item'));
        if (chips.length === 0) return;

        chips.forEach(function (chip) { chip.classList.remove('d-none'); });

        if (isDesktop()) return; // Full wrap, no cap — matches existing desktop behavior.

        // Selected chips always first, so truncation can never hide the
        // current selection; relative order preserved within each group.
        var selected = chips.filter(function (c) { return c.dataset.selected === 'true'; });
        var unselected = chips.filter(function (c) { return c.dataset.selected !== 'true'; });
        var ordered = selected.concat(unselected);
        ordered.forEach(function (chip) { wrap.appendChild(chip); });

        var rows = rowsFor(ordered);
        if (rows.length <= 2) return; // Fits in 2 lines — no overflow chip at all.

        var lastSelectedRow = -1;
        rows.forEach(function (row, index) {
            if (row.some(function (c) { return c.dataset.selected === 'true'; })) {
                lastSelectedRow = index;
            }
        });
        // Keep at least 2 lines (index 0-1); extend further only if the
        // selection itself spans beyond that — a 3rd line beats hiding
        // part of the current selection.
        var cutoffRow = Math.max(1, lastSelectedRow);

        var hiddenCount = 0;
        rows.forEach(function (row, index) {
            if (index > cutoffRow) {
                row.forEach(function (chip) {
                    chip.classList.add('d-none');
                    hiddenCount++;
                });
            }
        });

        if (hiddenCount > 0) {
            var overflowChip = document.createElement('button');
            overflowChip.type = 'button';
            overflowChip.className = 'chip-picker-overflow btn btn-sm btn-outline-secondary text-nowrap';
            overflowChip.style.minHeight = '44px';
            overflowChip.setAttribute('data-bs-toggle', 'offcanvas');
            overflowChip.setAttribute('data-bs-target', container.dataset.sheetTarget);
            overflowChip.textContent = '+' + hiddenCount;
            wrap.appendChild(overflowChip);
        }
    }

    function setItemSelected(el, selected) {
        el.dataset.selected = selected ? 'true' : 'false';
        el.setAttribute('aria-pressed', selected ? 'true' : 'false');
        if (el.classList.contains('chip-picker-chip')) {
            el.classList.toggle('btn-primary', selected);
            el.classList.toggle('btn-outline-secondary', !selected);
        }
        if (el.classList.contains('chip-picker-sheet-row')) {
            var check = el.querySelector('.bi-check-lg');
            if (check) check.classList.toggle('invisible', !selected);
        }
    }

    function initMulti(container) {
        var sheet = document.querySelector(container.dataset.sheetTarget || '');

        function selectedIds() {
            return Array.prototype.slice
                .call(container.querySelectorAll('.chip-picker-item[data-selected="true"]'))
                .map(function (el) { return el.dataset.id; });
        }

        function toggle(id) {
            var nextSelected = null;
            var all = Array.prototype.slice.call(container.querySelectorAll('.chip-picker-item[data-id="' + id.replace(/["\\]/g, '\\$&') + '"]'));
            if (sheet) {
                all = all.concat(Array.prototype.slice.call(sheet.querySelectorAll('.chip-picker-item[data-id="' + id.replace(/["\\]/g, '\\$&') + '"]')));
            }
            all.forEach(function (el) {
                if (nextSelected === null) nextSelected = el.dataset.selected !== 'true';
                setItemSelected(el, nextSelected);
            });

            truncate(container);
            container.dispatchEvent(new CustomEvent('chip-picker:change', {
                detail: { selectedIds: selectedIds() },
                bubbles: true
            }));
        }

        function onClick(e) {
            var el = e.target.closest('.chip-picker-item');
            if (!el) return;
            toggle(el.dataset.id);
        }

        container.addEventListener('click', onClick);
        if (sheet) sheet.addEventListener('click', onClick);
    }

    function init() {
        document.querySelectorAll('.chip-picker').forEach(function (container) {
            truncate(container);
            if (container.dataset.mode === 'multi') initMulti(container);
        });
    }

    init();

    // Generic escape hatch for mode:multi callers that need to correct a
    // selection from outside a user click — e.g. reverting the optimistic
    // toggle when their own persistence call (chip-picker:change's
    // listener) comes back rejected by the server. Applies to the chip and
    // its mirrored sheet row, re-truncates, but never dispatches
    // chip-picker:change itself (the caller already knows what happened;
    // re-dispatching would loop back into its own listener).
    function setSelected(pickerId, id, selected) {
        var container = document.getElementById(pickerId);
        if (!container) return;
        var sheet = document.querySelector(container.dataset.sheetTarget || '');
        var selector = '.chip-picker-item[data-id="' + String(id).replace(/["\\]/g, '\\$&') + '"]';
        var all = Array.prototype.slice.call(container.querySelectorAll(selector));
        if (sheet) all = all.concat(Array.prototype.slice.call(sheet.querySelectorAll(selector)));
        all.forEach(function (el) { setItemSelected(el, selected); });
        truncate(container);
    }

    window.ChipPicker = window.ChipPicker || {};
    window.ChipPicker.setSelected = setSelected;

    var resizeTimer;
    function scheduleRetruncate() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            document.querySelectorAll('.chip-picker').forEach(truncate);
        }, 150);
    }
    window.addEventListener('resize', scheduleRetruncate);
    window.addEventListener('orientationchange', scheduleRetruncate);
})();
