/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The deposit form's file picker (modules/attestations/views/index.html.twig).
//
// Two jobs, and no more: wire the shared drop zone to the form's own file
// input, and name the chosen file on screen. A visually-hidden input tells
// a reader nothing about what they just picked, and « Découper le fichier »
// is not a button anybody should press unsure of which file it will cut.
//
// A drop replaces the input's own FileList (a dropped file is not otherwise
// submitted with the form), so the classic multipart POST carries whichever
// file the reader last chose, dropped or picked.
(function () {
    var ZONE_ID = 'attestations-drop-zone';
    var INPUT_ID = 'attestations-pdf';
    var NAME_ID = 'attestations-file-name';

    /**
     * The one line of French this script writes, and it names a file the
     * reader themselves just chose.
     *
     * @param {HTMLElement} target
     * @param {File|null} file
     * @returns {void}
     */
    function showFileName(target, file) {
        target.textContent = file ? file.name : '';
        target.hidden = !file;
    }

    /**
     * A dropped file has to be put back into the input, or the form posts
     * nothing: the drop event and the input's own FileList are two
     * different things, and only the second is submitted.
     *
     * DataTransfer is what makes a FileList assignable; where it is not
     * available the drop simply does not stick, and the picker still works.
     *
     * @param {HTMLInputElement} input
     * @param {FileList} files
     * @returns {boolean} whether the input now holds the file
     */
    function adoptFiles(input, files) {
        if (!files || files.length === 0 || typeof DataTransfer !== 'function') {
            return false;
        }

        try {
            var transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
            return true;
        } catch {
            // DataTransfer is not constructible everywhere; the caller
            // keeps the file input as the user left it.
            return false;
        }
    }

    function init() {
        var zone = document.getElementById(ZONE_ID);
        var input = /** @type {HTMLInputElement|null} */ (document.getElementById(INPUT_ID));
        var nameEl = document.getElementById(NAME_ID);

        if (!zone || !input || !nameEl) {
            return;
        }

        input.addEventListener('change', function () {
            showFileName(nameEl, input.files?.length > 0 ? input.files[0] : null);
        });

        if (window.ScoutMagicDropZone) {
            window.ScoutMagicDropZone.bind(zone, function (files) {
                if (adoptFiles(input, files)) {
                    showFileName(nameEl, files[0]);
                }
            }, { input: input });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.ScoutMagicAttestationsDeposit = { adoptFiles: adoptFiles, showFileName: showFileName };
})();
