/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The column picker of the free-text page form — see
// core/View/templates/config/text_pages/form.html.twig.
//
// Four of the five menus are split into named columns and one is not, so
// the second picker follows the first: it is filled from the chosen
// section's columns, and hidden entirely for a section that has none.
//
// This is a convenience, never the rule. The pair is validated again
// server-side by TextPageService::assertMenuPlacement(), because
// MenuBuilder::addPage() throws on a column its menu does not declare —
// and it throws while building the navigation of EVERY page of the site,
// so a hand-made request that got past the browser would not break its
// own page, it would break the menu for everyone.
(function () {
    /**
     * @param {HTMLFormElement} form
     */
    function wire(form) {
        var section = /** @type {HTMLSelectElement|null} */ (form.querySelector('[name="menu_id"]'));
        var group = /** @type {HTMLSelectElement|null} */ (form.querySelector('[name="menu_group"]'));
        var wrapper = /** @type {HTMLElement|null} */ (form.querySelector('[data-text-page-group-field]'));
        if (!section || !group || !wrapper) return;

        /** @type {Record<string, Record<string, string>>} */
        var groups = {};
        try {
            groups = JSON.parse(form.dataset.groups || '{}');
        } catch (e) {
            // A malformed payload must not leave the form unusable: the
            // picker then offers nothing and the server decides, which is
            // where the decision belongs anyway.
            groups = {};
        }

        var preselectEl = form.querySelector('[data-text-page-selected-group]');
        var preselected = preselectEl ? preselectEl.getAttribute('value') || '' : '';

        /**
         * @param {string} keep the column to re-select when it still exists
         */
        function refresh(keep) {
            var columns = groups[section.value] || {};
            var ids = Object.keys(columns);

            group.innerHTML = '';
            ids.forEach(function (id) {
                var option = document.createElement('option');
                option.value = id;
                option.textContent = columns[id];
                group.appendChild(option);
            });

            if (ids.length === 0) {
                // Hidden AND disabled: a hidden control still submits, and
                // an empty string is not the null the server expects.
                wrapper.hidden = true;
                group.disabled = true;
                return;
            }

            wrapper.hidden = false;
            group.disabled = false;
            group.value = ids.includes(keep) ? keep : ids[0];
        }

        section.addEventListener('change', function () {
            refresh(group.value);
        });

        refresh(preselected);
    }

    document.querySelectorAll('form[data-text-page-form]').forEach(
        /** @param {Element} form */
        function (form) {
            wire(/** @type {HTMLFormElement} */ (form));
        }
    );
})();
