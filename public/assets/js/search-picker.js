/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The generic search picker (core/View/templates/partials/search_picker.html.twig,
// ARCHITECTURE.md §8.30bis): type, wait a beat, ask the server, click a
// result — in single or multiple choice.
//
// Wires every `[data-search-picker]` on the page, each owning its own
// values, so nothing here is global but the document listener that closes
// an open list.
//
// **Upgrading REMOVES the list this replaces.** The server always renders a
// real select element holding the shortlist and whatever is already
// retained, named exactly like the values posted here; it is what the form
// submits without JavaScript. Leaving it in the DOM would post the field
// twice, so the upgrade enables this half and deletes that one, in that
// order.
//
// Every string drawn here came off the wire, so every one is written with
// textContent and never as markup.
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    /**
     * @typedef {{id: number|string, label: string, subtitle?: string, badge?: string, warning?: string}} PickerRow
     */

    /**
     * The retained rows the server rendered, or none: a picker that starts
     * empty beats a page whose script died on a malformed attribute.
     *
     * @param {HTMLElement} picker
     * @returns {PickerRow[]}
     */
    function initialSelection(picker) {
        try {
            var rows = JSON.parse(picker.dataset.selected || '[]');
            return Array.isArray(rows) ? rows.filter(function (row) {
                return row?.id !== undefined && row.id !== null && row.label !== undefined;
            }) : [];
        } catch (e) {
            // A malformed attribute is the server's bug, not the reader's:
            // the picker starts empty rather than taking the page down.
            return [];
        }
    }

    /**
     * The search URL with the query appended, whether or not the caller's
     * URL already carries one.
     *
     * @param {string} base
     * @param {string} query
     * @returns {string}
     */
    function searchUrl(base, query) {
        return base + (base.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
    }

    /**
     * The selection without one row, compared by id as a string because
     * the server's ids and the dataset's may differ in type.
     *
     * @param {PickerRow[]} rows
     * @param {PickerRow} row
     * @returns {PickerRow[]}
     */
    function withoutRow(rows, row) {
        return rows.filter(function (other) {
            return String(other.id) !== String(row.id);
        });
    }

    /**
     * @param {HTMLElement} picker
     */
    function wire(picker) {
        var box = /** @type {HTMLElement|null} */ (picker.querySelector('[data-search-picker-search]'));
        var fallback = picker.querySelector('[data-search-picker-fallback]');
        if (!box) {
            return;
        }
        var search = /** @type {HTMLInputElement|null} */ (box.querySelector('input[type="text"]'));
        var results = /** @type {HTMLElement|null} */ (box.querySelector('[data-search-picker-results]'));
        var values = /** @type {HTMLElement|null} */ (box.querySelector('[data-search-picker-values]'));
        var chosenBox = /** @type {HTMLElement|null} */ (box.querySelector('[data-search-picker-chosen]'));
        if (!search || !results || !values) {
            return;
        }

        var multiple = picker.dataset.mode === 'multiple';
        var required = picker.dataset.required === '1';
        var fieldName = picker.dataset.fieldName || '';
        var emptyLabel = picker.dataset.emptyLabel || 'Aucun résultat ne correspond.';
        var baseUrl = picker.dataset.searchUrl || '';

        /** @type {PickerRow[]} */
        var selected = initialSelection(picker);
        if (!multiple) {
            selected = selected.slice(0, 1);
        }

        var timeout = null;
        // True from a keystroke until the answer to it is on screen: the
        // pause AND the request in flight. While it holds, the list shows
        // an older query's rows.
        var pending = false;
        // Only the answer to the LAST request is drawn: a slow reply to
        // « fê » must not overwrite the list for « fête ».
        var requestNumber = 0;

        /** @param {number|string} id */
        function isSelected(id) {
            return selected.some(function (row) {
                return String(row.id) === String(id);
            });
        }

        function announce() {
            picker.dispatchEvent(new CustomEvent('search-picker:change', {
                bubbles: true,
                detail: { selected: selected.slice() },
            }));
        }

        /** The hidden inputs are rebuilt from `selected`, never patched. */
        function writeValues() {
            values.replaceChildren();
            var rows = multiple ? selected : [selected[0] || { id: '', label: '' }];
            rows.forEach(function (row) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = fieldName;
                input.value = String(row.id);
                values.appendChild(input);
            });
        }

        function drawChosen() {
            if (!chosenBox) {
                return;
            }
            chosenBox.replaceChildren();
            selected.forEach(function (row) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'btn btn-sm btn-primary';
                chip.setAttribute('aria-label', 'Retirer ' + row.label);

                var text = document.createElement('span');
                text.textContent = row.label;
                chip.appendChild(text);

                var cross = document.createElement('i');
                cross.className = 'bi bi-x ms-1';
                cross.setAttribute('aria-hidden', 'true');
                chip.appendChild(cross);

                chip.addEventListener('click', function () {
                    selected = withoutRow(selected, row);
                    refresh();
                    announce();
                    search.focus();
                });
                chosenBox.appendChild(chip);
            });
        }

        function refresh() {
            writeValues();
            drawChosen();
            validate();
        }

        function hide() {
            results.classList.add('d-none');
        }

        /**
         * Nothing in flight may reopen the list once the reader has chosen:
         * the pending pause is cancelled and every answer still on its way
         * becomes stale.
         */
        function settle() {
            clearTimeout(timeout);
            timeout = null;
            pending = false;
            requestNumber++;
        }

        /**
         * The reader closed the list (Escape, a click elsewhere): a search
         * still under way must not reopen it behind their back.
         */
        function dismiss() {
            settle();
            hide();
        }

        /**
         * A required single picker refuses to submit empty — the rule the
         * removed select carried, moved onto the box that replaced it.
         */
        function validate() {
            if (required && !multiple) {
                search.setCustomValidity(selected.length > 0 ? '' : 'Choisissez un élément dans la liste.');
            }
        }

        /** @param {PickerRow} row */
        function choose(row) {
            settle();
            if (multiple) {
                if (!isSelected(row.id)) {
                    selected.push(row);
                }
                // Cleared so the next search starts from nothing — the
                // chosen row now lives in the chips above.
                search.value = '';
            } else {
                selected = [row];
                // Shown IN the input rather than on a line under it, the
                // way every earlier copy of this picker did.
                search.value = row.label;
            }
            hide();
            results.replaceChildren();
            refresh();
            announce();
        }

        /** @param {PickerRow} row */
        function resultButton(row) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action text-start d-flex align-items-start gap-2';

            var body = document.createElement('span');
            body.className = 'flex-grow-1';
            var name = document.createElement('span');
            name.className = 'd-block';
            name.textContent = row.label;
            body.appendChild(name);

            if (row.subtitle) {
                var subtitle = document.createElement('span');
                subtitle.className = 'd-block small text-body-secondary';
                subtitle.textContent = row.subtitle;
                body.appendChild(subtitle);
            }
            if (row.warning) {
                var warning = document.createElement('span');
                warning.className = 'd-block small text-warning-emphasis';
                warning.textContent = row.warning;
                body.appendChild(warning);
            }
            button.appendChild(body);

            if (row.badge) {
                var badge = document.createElement('span');
                badge.className = 'badge text-bg-light border';
                badge.textContent = row.badge;
                button.appendChild(badge);
            }

            button.addEventListener('click', function () {
                choose(row);
            });
            return button;
        }

        /** @param {PickerRow[]} rows */
        function render(rows) {
            results.replaceChildren();
            // A row already retained never comes back as a suggestion: in
            // multiple mode choosing it again would do nothing, and a list
            // offering it reads as though it had not been taken.
            var fresh = rows.filter(function (row) {
                return !(multiple && isSelected(row.id));
            });
            if (fresh.length === 0) {
                // Said rather than left blank: an empty box that simply
                // stops answering reads as a broken field.
                var empty = document.createElement('div');
                empty.className = 'list-group-item text-body-secondary small';
                empty.textContent = emptyLabel;
                results.appendChild(empty);
            } else {
                fresh.forEach(function (row) {
                    results.appendChild(resultButton(row));
                });
            }
            results.classList.remove('d-none');
        }

        async function run() {
            timeout = null;
            var mine = ++requestNumber;
            var query = search.value.trim();
            if (query === '') {
                pending = false;
                hide();
                return;
            }
            var res = await window.ScoutMagicApi.getJson(searchUrl(baseUrl, query));
            if (mine !== requestNumber) {
                return;
            }
            pending = false;
            var rows = res.data?.success && Array.isArray(res.data.results) ? res.data.results : [];
            render(rows);
        }

        // The upgrade: this half enabled, the fallback list removed.
        refresh();
        if (!multiple && selected[0]) {
            search.value = selected[0].label;
        }
        box.classList.remove('d-none');
        if (fallback) {
            fallback.remove();
        }

        search.addEventListener('input', function () {
            if (!multiple && selected.length > 0) {
                // Typing again un-chooses: the posted id and the visible
                // name must never disagree.
                selected = [];
                refresh();
                announce();
            }
            pending = true;
            // An answer still in flight is to the query typed before this
            // keystroke: it must not land during the new pause and clear
            // `pending` for a list the reader has already typed past.
            requestNumber++;
            clearTimeout(timeout);
            timeout = setTimeout(run, DEBOUNCE_MS);
        });

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                dismiss();
            } else if (event.key === 'Enter' && (pending || !results.classList.contains('d-none'))) {
                // Enter in a search box must not submit the surrounding
                // form half-filled; it picks the first suggestion instead —
                // but only a suggestion for what is typed NOW: while a
                // search is pending (the pause, or the request in flight),
                // the list on screen answers an older query, and Enter
                // does nothing.
                event.preventDefault();
                if (pending) {
                    return;
                }
                var first = /** @type {HTMLButtonElement|null} */ (results.querySelector('button'));
                if (first) {
                    first.click();
                }
            }
        });

        document.addEventListener('click', function (event) {
            // The path is read as it was at dispatch: a chip's own handler
            // has already redrawn the chips, so the button clicked is no
            // longer inside the picker by the time this runs.
            if (!event.composedPath().includes(picker)) {
                dismiss();
            }
        });
    }

    document.querySelectorAll('[data-search-picker]').forEach(function (picker) {
        wire(/** @type {HTMLElement} */ (picker));
    });
})();
