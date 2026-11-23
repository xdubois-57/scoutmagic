/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The sheet of map applications a carpool page's « Itinéraire » button
// opens (modules/covoiturage/views/show.html.twig, issue #1345).
//
// Bootstrap opens and closes the sheet from its data attributes — the
// button carries `data-bs-toggle="offcanvas"`, the close button
// `data-bs-dismiss` — and the three links are rendered by the server
// (Core\Geo\MapsLink is the only place their format is written). This
// script does the one thing the attributes cannot: `data-bs-dismiss` on a
// link cancels the click, so the links cannot carry it, and without this
// the sheet would still be open when the reader comes back from the
// application they chose. The same gesture as phone-link.js's sheet.
//
// It never cancels the click, remembers nothing (no storage, no cookie:
// the sheet opens fresh every time) and does nothing without Bootstrap —
// the link opens either way.
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = /** @type {Element|null} */ (event.target);
        if (!target || !target.closest) {
            return;
        }
        var choice = target.closest('[data-directions-choice]');
        if (!choice) {
            return;
        }
        var sheet = choice.closest('[data-directions-sheet]');
        var bootstrap = /** @type {any} */ (window).bootstrap;
        if (!sheet || !bootstrap || !bootstrap.Offcanvas) {
            return;
        }
        var panel = bootstrap.Offcanvas.getInstance(sheet);
        if (panel) {
            panel.hide();
        }
    });
})();
