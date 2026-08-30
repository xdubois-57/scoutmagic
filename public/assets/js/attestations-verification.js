/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The attestations verification screen
// (modules/attestations/views/batch.html.twig).
//
// Three behaviours, and each one exists because getting it wrong publishes
// a batch nobody can audit afterwards — a certificate is readable by its
// family and by nobody else once it is out.
//
//  1. FILTERING HIDES, IT NEVER REMOVES. A filtered row keeps its checkbox
//     and its state. If the filter took nodes out of the DOM, unticking the
//     animateurs and then filtering on « Scout » would silently erase the
//     decisions already taken, and the batch would go out short with
//     nothing on screen saying so.
//
//  2. THE BULK COMMAND ACTS ON WHAT IS SHOWN, AND ITS LABEL SAYS HOW MANY.
//     « Tout désélectionner » on a filtered screen reads as the whole batch
//     and touches part of it. The label carries the count of visible rows,
//     recomputed on every filter change.
//
//  3. UNRESOLVED ROWS ARE NEVER HIDDEN. A row with no member has no
//     function, so any function filter would take it away — and it is
//     exactly the row somebody still has to deal with.
//
// The counters follow: « N affichées sur M » is about the filter, « N à
// distribuer » is about the batch, and only the second one decides anything.
(function () {
    var FILTER_ID = 'attestations-function-filter';
    var LIST_ID = 'attestations-lines';
    var VISIBLE_COUNT_ID = 'attestations-visible-count';
    var SELECTED_COUNT_ID = 'attestations-selected-count';
    var TOGGLE_ID = 'attestations-toggle-visible';
    var HIDDEN_CLASS = 'd-none';

    /**
     * @param {HTMLElement} list
     * @returns {HTMLElement[]}
     */
    function rowsOf(list) {
        return /** @type {HTMLElement[]} */ (
            Array.prototype.slice.call(list.querySelectorAll('.attestations-line'))
        );
    }

    /**
     * @param {HTMLElement} row
     * @returns {boolean}
     */
    function isVisible(row) {
        return !row.classList.contains(HIDDEN_CLASS);
    }

    /**
     * @param {HTMLElement} row
     * @returns {HTMLInputElement|null}
     */
    function checkboxOf(row) {
        return /** @type {HTMLInputElement|null} */ (row.querySelector('.attestations-line-check'));
    }

    /**
     * A row is shown when nothing is filtered, when its own function is
     * among the chosen ones, or when it is one of the rows that must never
     * be filtered away at all.
     *
     * @param {HTMLElement} row
     * @param {string[]} selectedFunctions
     * @returns {boolean}
     */
    function shouldShow(row, selectedFunctions) {
        if (row.dataset.alwaysVisible === '1') {
            return true;
        }
        if (selectedFunctions.length === 0) {
            return true;
        }
        return selectedFunctions.indexOf(row.dataset.function || '') !== -1;
    }

    /**
     * @param {HTMLElement[]} rows
     * @param {string[]} selectedFunctions
     * @returns {number} how many rows are now shown
     */
    function applyFilter(rows, selectedFunctions) {
        var shown = 0;
        rows.forEach(function (row) {
            var show = shouldShow(row, selectedFunctions);
            row.classList.toggle(HIDDEN_CLASS, !show);
            if (show) {
                shown++;
            }
        });

        return shown;
    }

    /**
     * The two counters, which answer two different questions and must not
     * be confused: how much of the batch is on screen, and how much of it
     * will be distributed.
     *
     * @param {HTMLElement[]} rows
     * @param {HTMLElement|null} visibleEl
     * @param {HTMLElement|null} selectedEl
     * @returns {void}
     */
    function refreshCounters(rows, visibleEl, selectedEl) {
        var visible = rows.filter(isVisible).length;
        var total = rows.length;
        var selected = rows.filter(function (row) {
            var box = checkboxOf(row);
            return box !== null && box.checked;
        }).length;

        if (visibleEl) {
            visibleEl.textContent = visible + ' affichée' + (visible > 1 ? 's' : '')
                + ' sur ' + total;
        }
        if (selectedEl) {
            selectedEl.textContent = String(selected);
        }
    }

    /**
     * Whether every visible, tickable row is already ticked — which decides
     * whether the command offers to select or to deselect.
     *
     * @param {HTMLElement[]} rows
     * @returns {boolean}
     */
    function allVisibleChecked(rows) {
        var tickable = rows.filter(function (row) {
            var box = checkboxOf(row);
            return isVisible(row) && box !== null && !box.disabled;
        });

        return tickable.length > 0 && tickable.every(function (row) {
            var box = checkboxOf(row);
            return box !== null && box.checked;
        });
    }

    /**
     * The label carries the number of rows the command will touch. Both
     * wordings are rendered server-side into data attributes, so this
     * script never invents user-facing French of its own.
     *
     * @param {HTMLElement} button
     * @param {HTMLElement[]} rows
     * @returns {void}
     */
    function refreshToggleLabel(button, rows) {
        var count = rows.filter(function (row) {
            var box = checkboxOf(row);
            return isVisible(row) && box !== null && !box.disabled;
        }).length;

        var template = allVisibleChecked(rows)
            ? (button.dataset.deselectLabel || '')
            : (button.dataset.selectLabel || '');

        button.textContent = template.replace('{n}', String(count));
        button.toggleAttribute('disabled', count === 0);
    }

    /**
     * @param {HTMLElement[]} rows
     * @returns {void}
     */
    function toggleVisible(rows) {
        var check = !allVisibleChecked(rows);
        rows.forEach(function (row) {
            var box = checkboxOf(row);
            if (isVisible(row) && box !== null && !box.disabled) {
                box.checked = check;
            }
        });
    }

    function init() {
        var list = document.getElementById(LIST_ID);
        if (!list) {
            return;
        }

        var rows = rowsOf(/** @type {HTMLElement} */ (list));
        var visibleEl = document.getElementById(VISIBLE_COUNT_ID);
        var selectedEl = document.getElementById(SELECTED_COUNT_ID);
        var toggle = document.getElementById(TOGGLE_ID);
        var filter = document.getElementById(FILTER_ID);

        function refresh() {
            refreshCounters(rows, visibleEl, selectedEl);
            if (toggle) {
                refreshToggleLabel(/** @type {HTMLElement} */ (toggle), rows);
            }
        }

        if (filter) {
            filter.addEventListener('select-bar:change', function (e) {
                var detail = /** @type {CustomEvent} */ (e).detail || {};
                applyFilter(rows, detail.selectedIds || []);
                refresh();
            });
        }

        list.addEventListener('change', function (e) {
            var target = /** @type {HTMLElement} */ (e.target);
            if (target && target.classList.contains('attestations-line-check')) {
                refresh();
            }
        });

        if (toggle) {
            toggle.addEventListener('click', function () {
                toggleVisible(rows);
                refresh();
            });
        }

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Exposed for the unit tests, which exercise the real production file
    // rather than a reimplementation of its logic (AGENTS.md § Tests).
    window.ScoutMagicAttestationsVerification = {
        applyFilter: applyFilter,
        shouldShow: shouldShow,
        allVisibleChecked: allVisibleChecked,
        refreshToggleLabel: refreshToggleLabel,
        refreshCounters: refreshCounters,
        toggleVisible: toggleVisible,
        rowsOf: rowsOf
    };
})();
