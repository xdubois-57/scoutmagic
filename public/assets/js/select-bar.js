/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Select bar — see core/View/templates/partials/select_bar.html.twig.
// Knows nothing about what an item "is", and owns exactly two things:
//
// 1. mode:multi selection — toggling a panel row and dispatching a
//    `select-bar:change` CustomEvent (detail: { selectedIds }) on the
//    container, so the caller can persist the choice however it needs
//    to. This script never persists anything itself.
// 2. Closing an open panel on a click outside it, or on Escape.
//
// Everything else the panel does — opening, closing, announcing its own
// expanded state — is native <details>/<summary> behaviour and needs no
// code at all. mode:single needs no JS whatsoever: its rows are plain
// <a href> to the destination, so a click, a screen reader, a JS-off
// browser and a cached offline page all already work.
//
// There is deliberately no truncation, no fold, and no post-render DOM
// measurement here. The component this replaced measured a two-line
// fold client-side after render, which cost a layout shift on every
// load and hid an arbitrary subset of the list behind a "+N" chip.
(function () {
    /**
     * @param {HTMLElement} el
     * @param {boolean} selected
     */
    function setItemSelected(el, selected) {
        el.dataset.selected = selected ? 'true' : 'false';
        el.setAttribute('aria-pressed', selected ? 'true' : 'false');
        var check = el.querySelector('.bi-check-lg');
        if (check) check.classList.toggle('invisible', !selected);
    }

    /**
     * The trigger summarises the selection rather than drawing every
     * pick: nothing selected → the bar's "none" text, exactly one →
     * that item's label, more → "N <plural noun>". Both texts are
     * rendered server-side into data attributes by the partial's Twig,
     * so this script never invents user-facing French of its own.
     *
     * @param {HTMLElement} container
     */
    function refreshSummary(container) {
        var value = container.querySelector('.select-bar-value');
        if (!value) return;

        var trigger = /** @type {HTMLElement} */ (value);
        var selected = /** @type {HTMLElement[]} */ (Array.prototype.slice.call(
            container.querySelectorAll('.select-bar-item[data-selected="true"]')
        ));

        if (selected.length === 0) {
            trigger.textContent = trigger.dataset.noneText || '';
            return;
        }
        if (selected.length === 1) {
            var labelEl = selected[0].querySelector('.select-bar-row-label');
            trigger.textContent = labelEl ? (labelEl.textContent || '').trim() : '';
            return;
        }
        trigger.textContent = selected.length + ' ' + (trigger.dataset.countLabel || '');
    }

    /**
     * @param {HTMLElement} container
     */
    function selectedIds(container) {
        return Array.prototype.slice
            .call(container.querySelectorAll('.select-bar-item[data-selected="true"]'))
            .map(function (el) { return /** @type {HTMLElement} */ (el).dataset.id; });
    }

    /**
     * @param {HTMLElement} container
     */
    function initMulti(container) {
        container.addEventListener('click', function (e) {
            var target = /** @type {HTMLElement} */ (e.target);
            var el = /** @type {HTMLElement|null} */ (target.closest('.select-bar-item'));
            if (!el || !container.contains(el)) return;

            setItemSelected(el, el.dataset.selected !== 'true');
            refreshSummary(container);
            container.dispatchEvent(new CustomEvent('select-bar:change', {
                detail: { selectedIds: selectedIds(container) },
                bubbles: true
            }));
        });
    }

    /**
     * A click anywhere outside an open panel closes it. Native <details>
     * has no such behaviour — it stays open until its own summary is
     * clicked again, which on a phone means the panel covering the page
     * content the visitor just tried to reach.
     *
     * @param {Event} e
     */
    function closeOnOutsideClick(e) {
        var target = /** @type {HTMLElement|null} */ (e.target);
        document.querySelectorAll('.select-bar details[open]').forEach(function (el) {
            var details = /** @type {HTMLDetailsElement} */ (el);
            if (target && details.contains(target)) return;
            details.open = false;
        });
    }

    /**
     * @param {KeyboardEvent} e
     */
    function closeOnEscape(e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.select-bar details[open]').forEach(function (el) {
            var details = /** @type {HTMLDetailsElement} */ (el);
            details.open = false;
            var summary = details.querySelector('summary');
            // Focus returns to the trigger, never nowhere — otherwise a
            // keyboard user who closes the panel loses their place.
            if (summary && details.contains(document.activeElement)) {
                /** @type {HTMLElement} */ (summary).focus();
            }
        });
    }

    function init() {
        document.querySelectorAll('.select-bar').forEach(function (containerEl) {
            var container = /** @type {HTMLElement} */ (containerEl);
            if (container.dataset.mode === 'multi') initMulti(container);
        });
        document.addEventListener('click', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);
    }

    init();

    // Escape hatch for mode:multi callers that need to correct a
    // selection from outside a user click — e.g. reverting the
    // optimistic toggle when their own persistence call (the
    // select-bar:change listener) comes back rejected by the server.
    // It applies the same visual update a click does and refreshes the
    // trigger summary, but never dispatches select-bar:change itself:
    // the caller already knows what happened, and re-dispatching would
    // loop straight back into its own listener.
    /**
     * @param {string} pickerId
     * @param {string} id
     * @param {boolean} selected
     */
    function setSelected(pickerId, id, selected) {
        var container = document.getElementById(pickerId);
        if (!container) return;
        var selector = '.select-bar-item[data-id="' + String(id).replace(/["\\]/g, '\\$&') + '"]';
        container.querySelectorAll(selector).forEach(function (el) {
            setItemSelected(/** @type {HTMLElement} */ (el), selected);
        });
        refreshSummary(/** @type {HTMLElement} */ (container));
    }

    window.SelectBar = window.SelectBar || {};
    window.SelectBar.setSelected = setSelected;
})();
