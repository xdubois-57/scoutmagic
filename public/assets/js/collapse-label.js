/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Afficher les 15 précédentes » ⇄ « Afficher moins » on a Bootstrap
// collapse trigger.
//
// Bootstrap toggles `.collapsed` and `aria-expanded` on the trigger
// itself, so the obvious implementation is two spans and one CSS rule
// hiding whichever one does not apply. That is what shipped, and
// production showed why it is the wrong call HERE: `/assets/css/app.css`
// is part of the service worker's app shell and is matched with
// `ignoreSearch` (see public/sw.js), so its `?v=` cache-buster does NOT
// force a refetch — until the previous worker is released, a page can
// legitimately render NEW html against the PREVIOUS stylesheet. A
// CSS-only swap then fails by showing both labels at once, and the
// button reads « Afficher les 15 précédentes Afficher moins ».
//
// So the label lives in the markup exactly once, and this file — not
// precached, therefore never stale — swaps its text. Degradation is the
// point: if this script never runs, the button still reads correctly in
// its collapsed state, it just stops changing.
//
// **Delegated on the document**, not bound to the triggers found at load.
// A rental booking's page re-renders its panels in place after every
// action (rental-booking.js), and a trigger it puts back is a new element
// that a load-time binding never saw: the label froze on it. Bootstrap's
// collapse events reach a capturing listener on the document whatever the
// element, so a trigger rendered at any time swaps like the first.
//
// The collapsed label is the markup's own text, read the first time the
// trigger is seen — or `data-collapse-label-collapsed`, which a trigger
// rendered OPEN must carry, its markup then holding the expanded label
// (the billing details of « Facture », open while they are incomplete).
(function () {
    /**
     * The label element of a trigger, its collapsed wording remembered on
     * the trigger itself so a later read never takes the expanded one.
     *
     * @param {HTMLElement} trigger
     * @returns {HTMLElement|null}
     */
    function labelOf(trigger) {
        var label = /** @type {HTMLElement|null} */ (trigger.querySelector('[data-collapse-label]'));
        if (label && trigger.dataset.collapseLabelCollapsed === undefined) {
            trigger.dataset.collapseLabelCollapsed = label.textContent || '';
        }

        return label;
    }

    /**
     * @param {Event} event a Bootstrap collapse event, on the collapse itself
     * @param {boolean} expanded
     * @returns {void}
     */
    function swap(event, expanded) {
        var target = event.target;
        if (!(target instanceof HTMLElement) || !target.id) {
            return;
        }
        var selector = '#' + target.id;

        document.querySelectorAll('[data-collapse-label-expanded]').forEach(function (node) {
            var trigger = /** @type {HTMLElement} */ (node);
            if ((trigger.dataset.bsTarget || '') !== selector) {
                return;
            }
            var label = labelOf(trigger);
            if (!label) {
                return;
            }
            var whenCollapsed = trigger.dataset.collapseLabelCollapsed || '';
            label.textContent = expanded ? (trigger.dataset.collapseLabelExpanded || whenCollapsed) : whenCollapsed;
        });
    }

    // Remembered now for the triggers already there, before any click can
    // change what their label reads.
    document.querySelectorAll('[data-collapse-label-expanded]').forEach(function (node) {
        labelOf(/** @type {HTMLElement} */ (node));
    });

    // `show`/`hide` rather than `shown`/`hidden`: the label belongs to
    // the click that was just made, not to the end of a 350 ms
    // animation the reader is already looking past. Capturing, so an
    // event that does not bubble reaches it too.
    document.addEventListener('show.bs.collapse', function (event) {
        swap(event, true);
    }, true);
    document.addEventListener('hide.bs.collapse', function (event) {
        swap(event, false);
    }, true);
})();
