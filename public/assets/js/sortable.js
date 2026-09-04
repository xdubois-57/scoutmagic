/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Drag-and-drop reordering of a list, in one place.
//
// Three screens had written this by hand — the shared list editor
// (list-editor.js), the gallery's media grid (gallery.js) and the
// finance category rules (finance-categories.js) — each with its own
// copy of the same dragstart / dragover / dragend triple, its own
// midpoint arithmetic, and its own dragging class.
//
// What the copies disagreed about, and what this file settles:
//
//  - WHEN the new order is saved. Two saved on `dragend`, one on the
//    item's `drop`. `drop` only fires when the pointer is released ON a
//    sibling item: releasing it just outside the list left the rows
//    visually reordered and the server none the wiser, until the next
//    reload put them back. `dragend` fires either way, which is the
//    only moment that always means « the gesture is over ».
//  - Delegation rather than a listener per item, so a list re-rendered
//    after an add or a delete needs no re-wiring.
//
// Not used by news-form-builder.js on purpose: that list reorders a
// state ARRAY and re-renders from it, so the DOM order is an output
// there, not the source of truth.
//
// Usage:
//   window.ScoutMagicSortable.bind(container, {
//       itemSelector: '.list-editor-item',
//       axis: 'y',                              // 'x' for a grid
//       draggingClass: 'list-editor-item--dragging',
//       onReorder: persistOrder,
//   })
(function () {
    /**
     * @param {HTMLElement|null} container
     * @param {{itemSelector: string, axis?: string, draggingClass?: string, onReorder?: () => void}} options
     * @returns {void}
     */
    function bind(container, options) {
        if (!container || !options?.itemSelector) {
            return;
        }
        if (container.dataset.sortableBound === '1') {
            return;
        }
        container.dataset.sortableBound = '1';

        var itemSelector = options.itemSelector;
        var horizontal = options.axis === 'x';
        var draggingClass = options.draggingClass || 'opacity-50';
        var dragged = /** @type {HTMLElement|null} */ (null);

        /** @param {Event} e */
        function itemOf(e) {
            var target = /** @type {HTMLElement|null} */ (e.target);
            return target?.closest
                ? /** @type {HTMLElement|null} */ (target.closest(itemSelector))
                : null;
        }

        container.addEventListener('dragstart', function (e) {
            var item = itemOf(e);
            if (!item) {
                return;
            }
            dragged = item;
            item.classList.add(draggingClass);
        });

        container.addEventListener('dragend', function (e) {
            var item = itemOf(e);
            if (item) {
                item.classList.remove(draggingClass);
            }
            if (!dragged) {
                return;
            }
            dragged = null;
            // Fires whether the pointer was released on a sibling or
            // anywhere else — the DOM already carries the new order
            // either way, so this is where it gets saved.
            if (options.onReorder) {
                options.onReorder();
            }
        });

        container.addEventListener('dragover', function (e) {
            // Without this the browser refuses the drop outright.
            e.preventDefault();

            var over = itemOf(e);
            if (!over || !dragged || over === dragged) {
                return;
            }

            var rect = over.getBoundingClientRect();
            var after = horizontal
                ? (/** @type {DragEvent} */ (e).clientX - rect.left) > rect.width / 2
                : (/** @type {DragEvent} */ (e).clientY - rect.top) > rect.height / 2;

            over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
        });
    }

    window.ScoutMagicSortable = { bind: bind };
})();
