/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The plumbing behind a `partials/drop_zone.html.twig` that simply posts
// its file with the form around it (design.md §7.10).
//
// `drop-zone.js` handles the four drag listeners and the click-to-pick;
// what it deliberately does NOT do is decide what happens to the file,
// because most of its callers upload it themselves. These zones don't:
// the file rides the form's own multipart POST, and the only thing left
// to do is the one thing the partial's hidden input makes necessary —
// SAY which file was picked. A drop zone whose input is
// `visually-hidden` and which never names what it holds looks exactly
// the same before and after a drop, which reads as a zone that swallowed
// the file.
//
// Usage: give the zone `data: { 'drop-zone-for': '<input id>' }` and put
// an element with id `<zone id>-selection` under it. Inert on a page with
// no such zone.

(function () {
    /**
     * @param {HTMLElement} zone
     * @param {FileList} files
     * @returns {void}
     */
    function describe(zone, files) {
        var target = document.getElementById(zone.id + '-selection');
        if (target === null) {
            return;
        }

        if (!files || files.length === 0) {
            target.textContent = '';
            return;
        }

        // Array.from, not for-of: what reaches here is a FileList — or, in
        // the tests, a FileList-alike — and an array-LIKE is not an
        // iterable. Reading it by length and index is the whole contract.
        var names = Array.from(files, function (file) { return file.name; });

        // Not "1 fichier sélectionné": the name is what tells somebody
        // they picked the holiday snap rather than the invoice.
        target.textContent = names.join(', ');
    }

    /**
     * @param {ParentNode} [root]
     * @returns {void}
     */
    function bind(root) {
        var dropZone = window.ScoutMagicDropZone;
        var scope = root || document;
        var zones = scope.querySelectorAll('[data-drop-zone-for]');

        for (const zone of zones) {
            (function (/** @type {HTMLElement} */ zone) {
                var input = /** @type {HTMLInputElement|null} */ (
                    document.getElementById(zone.dataset.dropZoneFor || '')
                );
                if (input === null) {
                    return;
                }

                // A drop hands over a FileList the input has never seen,
                // so it is copied onto the input — otherwise the form
                // posts nothing and the visitor gets « Choisissez un
                // fichier » after dropping one.
                if (dropZone) {
                    dropZone.bind(zone, function (files) {
                        if (typeof DataTransfer === 'function') {
                            var carrier = new DataTransfer();
                            for (const file of Array.from(files)) {
                                carrier.items.add(file);
                            }
                            input.files = carrier.files;
                        }
                        // The copy above is what the form actually
                        // posts; the delivered list is the fallback where
                        // DataTransfer is missing, so the visitor is at
                        // least told what they dropped.
                        describe(zone, input.files && input.files.length ? input.files : files);
                    }, { input: input });
                } else {
                    // No drop-zone.js on the page: the zone is then just a
                    // label around a hidden input, and picking a file must
                    // still say which one.
                    input.addEventListener('change', function () {
                        describe(zone, input.files);
                    });
                }
            })(/** @type {HTMLElement} */ (zone));
        }
    }

    window.ScoutMagicUploadDropZone = { bind: bind, describe: describe };

    bind(document);
})();
