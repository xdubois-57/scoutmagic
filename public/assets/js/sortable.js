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
//       group: 'text-pages',                    // optional, see below
//   })
//
// **Connected lists are opt-in** (issue #752). Containers bound with the
// same `group` accept each other's items: while one is dragged every list
// of the group is marked as a drop zone (`sortable-drop-zone`), an empty
// list accepts a drop, and `onReorder` is called ONCE, on the list the
// item ended up in, with `{item, from, to}`. Without `group` nothing
// changes: a list only ever reorders its own items, which is what every
// other screen using this file relies on.
(function () {
    /**
     * Shared per group: what is being dragged, and from where. A list
     * outside any group keeps its own, so it can never receive a foreign
     * item.
     *
     * @type {Object<string, {dragged: HTMLElement|null, source: HTMLElement|null, origin: {parent: Node|null, next: Node|null}|null, dropped: boolean, members: HTMLElement[]}>}
     */
    var groups = {};

    /**
     * @param {HTMLElement|null} container
     * @param {{itemSelector: string, axis?: string, draggingClass?: string, group?: string,
     *          onReorder?: (move?: {item: HTMLElement, from: HTMLElement, to: HTMLElement}) => void}} options
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
        if (options.group && !groups[options.group]) {
            groups[options.group] = { dragged: null, source: null, origin: null, dropped: false, members: [] };
        }
        var state = options.group
            ? groups[options.group]
            : { dragged: null, source: null, origin: null, dropped: false, members: [container] };
        if (options.group) {
            state.members.push(container);
        }

        /** @param {boolean} on */
        function markDropZones(on) {
            if (!options.group) {
                return;
            }
            state.members.forEach(function (member) {
                member.classList.toggle('sortable-drop-zone', on);
            });
        }

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
            state.dragged = item;
            state.source = container;
            // Where it started, to put it back if a move between lists is
            // abandoned (Escape, released outside every list).
            state.origin = { parent: item.parentNode, next: item.nextSibling };
            state.dropped = false;
            item.classList.add(draggingClass);
            markDropZones(true);
        });

        // The dragged item has its own `dragend`, which bubbles through the
        // list it is IN NOW — the target list when it crossed over, which
        // is the one whose `onReorder` has to save.
        container.addEventListener('dragend', function (e) {
            var item = itemOf(e);
            if (item) {
                item.classList.remove(draggingClass);
            }
            if (!state.dragged) {
                return;
            }
            var moved = state.dragged;
            var from = state.source || container;
            var origin = state.origin;
            var dropped = state.dropped;
            state.dragged = null;
            state.source = null;
            state.origin = null;
            markDropZones(false);
            // A move into ANOTHER list commits only on a real drop: dragover
            // moved the node live, so an abandoned gesture would otherwise
            // be saved — and between connected lists that can change who
            // sees an item. Put it back where it was, and save nothing.
            if (from !== container && !dropped) {
                if (origin?.parent) {
                    origin.parent.insertBefore(moved, origin.next);
                }
                return;
            }
            // Fires whether the pointer was released on a sibling or
            // anywhere else — the DOM already carries the new order
            // either way, so this is where it gets saved.
            if (options.onReorder) {
                options.onReorder({ item: moved, from: from, to: container });
            }
        });

        container.addEventListener('drop', function (e) {
            if (state.dragged) {
                e.preventDefault();
                state.dropped = true;
            }
        });

        container.addEventListener('dragover', function (e) {
            // Without this the browser refuses the drop outright.
            e.preventDefault();

            var dragged = state.dragged;
            if (!dragged) {
                return;
            }

            var over = itemOf(e);
            if (over === dragged) {
                return;
            }
            if (!over) {
                // Over the list but not over an item — an empty list, or
                // the space below the last item. Only a connected list
                // takes the item there; a lone list has nothing to gain.
                if (options.group && dragged.parentNode !== container) {
                    // After the last item rather than at the very end: a
                    // list may close on a non-item (an « empty » note),
                    // and the item belongs among the items.
                    var items = container.querySelectorAll(itemSelector);
                    var last = items.length ? items[items.length - 1] : null;
                    if (last?.parentNode === container) {
                        container.insertBefore(dragged, last.nextSibling);
                    } else {
                        container.insertBefore(dragged, container.firstChild);
                    }
                }
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
