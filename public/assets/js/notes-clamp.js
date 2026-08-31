/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Show me the first twenty lines, and a button if there is more. »
//
// Release notes are author-written Markdown of no fixed length: a patch
// release ships two lines, a version that lands a whole module ships
// several screens of them, and Configuration > Maintenance renders two of
// these blocks at once (the installed version's and the available one's).
// Left unbounded they pushed the install button, the dependency warning
// and the update history far below the fold — on the one page an
// administrator opens precisely to reach those.
//
// The clamp itself is CSS (`.notes-clamp` in app.css): height, not line
// count, because the content is rendered Markdown — headings, lists and
// code blocks all have their own line box, so counting source lines
// server-side would cut mid-element and produce broken HTML.
//
// What needs JavaScript is only the question CSS cannot answer: DID it
// overflow? A three-line note must not grow a « Voir la description
// complète » button that reveals nothing. So the button ships hidden and
// is revealed here, per block, after measuring.
(function () {
    /**
     * A clamped block whose content is taller than the clamp lets show.
     *
     * The tolerance absorbs sub-pixel rounding: a block whose content is
     * a fraction of a pixel taller than its box is NOT overflowing in any
     * sense a reader would notice, and revealing a button for it is the
     * failure mode this whole function exists to avoid.
     *
     * @param {HTMLElement} block
     * @returns {boolean}
     */
    function overflows(block) {
        return block.scrollHeight - block.clientHeight > 2;
    }

    /**
     * @param {HTMLElement} block
     * @param {HTMLElement} button
     */
    function wire(block, button) {
        if (!overflows(block)) {
            // Nothing hidden: drop the clamp so the block is never a box
            // with a fade over content that already fits, and leave the
            // button hidden.
            block.classList.remove('notes-clamp');
            return;
        }

        button.hidden = false;

        var collapsedLabel = button.dataset.labelCollapsed || button.textContent || '';
        var expandedLabel = button.dataset.labelExpanded || collapsedLabel;
        var label = button.querySelector('[data-notes-clamp-label]');

        button.addEventListener('click', function () {
            var expanded = block.classList.toggle('is-expanded');
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (label) {
                label.textContent = expanded ? expandedLabel : collapsedLabel;
            }
        });
    }

    /**
     * Markup-driven, like reveal-details.js: a block declares that it is
     * clampable and names its button, so a third screen needing this
     * needs no JavaScript of its own.
     *
     *   <div class="notes-clamp" data-notes-clamp="update-notes-toggle">…</div>
     *   <button id="update-notes-toggle" hidden …>
     */
    document.querySelectorAll('[data-notes-clamp]').forEach(function (node) {
        var block = /** @type {HTMLElement} */ (node);
        var button = document.getElementById(block.dataset.notesClamp || '');
        if (button) {
            wire(block, /** @type {HTMLElement} */ (button));
        }
    });
})();
