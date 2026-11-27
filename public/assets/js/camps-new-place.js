/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The stay form's « nouveau lieu » block (modules/camps/views/
// camp_form.html.twig, #1353). Its fields — and the core's point picker in
// it — matter only while « Un nouveau lieu » is chosen in « Lieu du
// séjour »: an existing place has its own address and its own point, which
// the server never overwrites from this block. So the block is hidden for
// an existing place, shown for a new one, and the map is told when it
// appears (Leaflet draws nothing in a box that had no size).
//
// Without this script the block simply stays visible, and works.
(function () {
    'use strict';

    var select = /** @type {HTMLSelectElement|null} */ (document.getElementById('place-id'));
    var block = document.getElementById('camps-new-place');
    if (!select || !block) {
        return;
    }
    var newPlace = block;
    var picker = /** @type {HTMLElement|null} */ (newPlace.querySelector('[data-point-picker]'));

    function sync() {
        var isNew = select?.value === '';
        newPlace.hidden = !isNew;
        if (isNew) {
            window.ScoutMagicPointPicker?.wire(picker)?.refresh();
        }
    }

    select.addEventListener('change', sync);
    sync();
})();
