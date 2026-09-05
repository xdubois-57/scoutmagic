/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The « drop files here » zone, in one place.
//
// Three screens had written this by hand — the generic uploader
// (upload.js), the gallery's album form (gallery.js) and « Nouveau reçu »
// (finance-receipt-form.js) — with three copies of the same four
// listeners and the same `border-primary` highlight, and only one of the
// three remembering the two subtleties:
//
//  - `dragover` MUST call preventDefault(), or the browser refuses the
//    drop and opens the file in a new tab instead. The zone looks alive
//    and does nothing.
//  - a hidden `<input type="file">` INSIDE the zone means a click on the
//    input bubbles back to the zone, whose handler clicks the input
//    again. Guarding on the event's target is what stops the picker
//    opening twice (or, on some browsers, looping).
//
// Usage:
//   window.ScoutMagicDropZone.bind(zone, onFiles, { input, pickOnClick })
//
// `onFiles` receives a FileList and is called for a drop, for the file
// picker's `change`, and for nothing else — the zone never decides what
// happens to a file.
(function () {
    var HIGHLIGHT = 'border-primary';

    /**
     * @param {HTMLElement|null} zone
     * @param {(files: FileList) => void} onFiles
     * @param {{input?: HTMLInputElement|null, pickOnClick?: boolean}} [options]
     * @returns {void}
     */
    function bind(zone, onFiles, options) {
        if (!zone || typeof onFiles !== 'function') {
            return;
        }
        // A page may wire the same zone from two code paths; the
        // listeners must not stack.
        if (zone.dataset.dropZoneBound === '1') {
            return;
        }
        zone.dataset.dropZoneBound = '1';

        var opts = options || {};
        var input = opts.input || null;

        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (e) {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add(HIGHLIGHT);
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (e) {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove(HIGHLIGHT);
            });
        });

        zone.addEventListener('drop', function (e) {
            var transfer = /** @type {DragEvent} */ (e).dataTransfer;
            if (transfer?.files?.length) {
                onFiles(transfer.files);
            }
        });

        if (!input) {
            return;
        }

        input.addEventListener('change', function () {
            if (input.files?.length) {
                onFiles(input.files);
            }
        });

        // A zone whose own controls are <label>s already opens their
        // pickers; wiring the whole zone on top of that would open two.
        if (opts.pickOnClick === false) {
            return;
        }

        zone.addEventListener('click', function (e) {
            // The click that came FROM the input (including the one this
            // very handler dispatched) must not re-open the picker.
            if (e.target === input) {
                return;
            }
            input.click();
        });
    }

    window.ScoutMagicDropZone = { bind: bind };
})();
