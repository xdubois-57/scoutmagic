/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The editing screen of a shared document
// (modules/documents/views/form.html.twig). Two things, both about saying
// a consequence at the moment it becomes real, never before:
//
// - « Lien direct » explains what unlisted means (not listed, not
//   protected) as soon as it is picked, and hides again otherwise;
// - the warning about replacing the file appears only once a file has
//   been chosen. Correcting a typo in the title has no consequence;
//   replacing the file has some that cannot be undone, and a warning shown
//   on every visit is a warning nobody reads (roadmap D8).
(function () {
    var form = document.getElementById('document-form');
    // A no-op on every other page of the site.
    if (!form) {
        return;
    }

    var directLinkHelp = document.getElementById('document-direct-link-help');
    var fileInput = /** @type {HTMLInputElement|null} */ (document.getElementById('document-file'));
    var replaceWarning = document.getElementById('document-replace-warning');

    function checkedVisibility() {
        var checked = /** @type {HTMLInputElement|null} */ (
            form.querySelector('input[name="visibility"]:checked')
        );
        return checked ? checked.value : '';
    }

    function updateDirectLinkHelp() {
        if (directLinkHelp) {
            directLinkHelp.classList.toggle('d-none', checkedVisibility() !== 'direct_link');
        }
    }

    function updateReplaceWarning() {
        if (replaceWarning && fileInput) {
            var chosen = fileInput.files !== null && fileInput.files.length > 0;
            replaceWarning.classList.toggle('d-none', !chosen);
        }
    }

    form.querySelectorAll('input[name="visibility"]').forEach(function (radio) {
        radio.addEventListener('change', updateDirectLinkHelp);
    });
    if (fileInput) {
        fileInput.addEventListener('change', updateReplaceWarning);
    }

    updateDirectLinkHelp();
    updateReplaceWarning();
})();
