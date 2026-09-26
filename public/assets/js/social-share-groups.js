/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Groupe de discussion » on a share page (modules/social): the groups
// chosen in the dialog are listed in clear beside the pencil that reopens
// it, and choosing one ticks « Groupe de discussion » — choosing none
// unticks it. Without this script the form still works: the groups are
// chosen in the dialog and sent with the box ticked by hand.
(function () {
    document.querySelectorAll('[data-share-groups]').forEach(function (node) {
        var root = /** @type {HTMLElement} */ (node);
        var toggle = /** @type {HTMLInputElement|null} */ (root.querySelector('[data-share-groups-toggle]'));
        var summary = /** @type {HTMLElement|null} */ (root.querySelector('[data-share-groups-summary]'));
        var boxes = /** @type {NodeListOf<HTMLInputElement>} */ (root.querySelectorAll('input[name="groups[]"]'));
        if (!toggle || !summary) {
            return;
        }
        var emptyLabel = summary.dataset.emptyLabel || '';

        /** @param {boolean} fromChoice */
        function refresh(fromChoice) {
            var names = [];
            boxes.forEach(function (box) {
                if (box.checked && !box.disabled) {
                    names.push(box.dataset.shareGroupName || box.value);
                }
            });
            summary.textContent = names.length > 0 ? names.join(', ') : emptyLabel;
            if (fromChoice && toggle) {
                toggle.checked = names.length > 0;
            }
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', function () {
                refresh(true);
            });
        });
        refresh(false);
    });
})();
