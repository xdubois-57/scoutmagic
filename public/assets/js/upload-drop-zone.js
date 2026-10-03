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
//
// A zone that also carries `data: { 'drop-zone-preview': 'image' }` gets a
// THUMBNAIL of each picked image above that name (issue #756). Opt-in, and
// deliberately not derived from `accept`: half these zones take a PDF or an
// image indifferently (a rental document, a receipt), and a zone that
// guessed would draw a broken frame for every PDF. The zones that take
// documents ask for nothing and keep exactly the behaviour they had.
//
// The preview is local and temporary — `URL.createObjectURL` on the picked
// File, revoked the moment it is replaced or cleared, so picking three
// photos in a row does not pin three decoded images in memory. Selecting a
// file uploads nothing: that is the form's own POST, later, when the
// visitor presses the button. Same reasoning, and the same proven shape,
// as the generic uploader's own preview in upload.js.

(function () {
    var PREVIEW_CLASS = 'drop-zone-preview';

    /**
     * Object URLs currently held by each zone's preview, so the next
     * selection can revoke them. Keyed by the zone's own element: a page
     * with two zones must not revoke the other one's images.
     *
     * @type {WeakMap<HTMLElement, string[]>}
     */
    var heldUrls = new WeakMap();

    /**
     * @param {HTMLElement} zone
     * @returns {void}
     */
    function releaseUrls(zone) {
        var held = heldUrls.get(zone);
        if (!held) {
            return;
        }
        held.forEach(function (url) {
            URL.revokeObjectURL(url);
        });
        heldUrls.delete(zone);
    }

    /**
     * Whether this zone asked for thumbnails. A zone that did not is left
     * exactly as it was — which is what keeps a document uploader from
     * sprouting a preview nobody asked for.
     *
     * @param {HTMLElement} zone
     * @returns {boolean}
     */
    function wantsPreview(zone) {
        return zone.dataset.dropZonePreview === 'image';
    }

    /**
     * One thumbnail, or null for anything that is not an image this
     * browser will decode. The caller then has only the name to show,
     * which is the right answer rather than a broken frame.
     *
     * @param {HTMLElement} zone
     * @param {File} file
     * @returns {HTMLImageElement|null}
     */
    function thumbnail(zone, file) {
        if (typeof file.type !== 'string' || file.type.indexOf('image/') !== 0) {
            return null;
        }
        if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
            return null;
        }

        var url = URL.createObjectURL(file);

        // Checked AT THE SINK, which is where AGENTS.md § CodeQL says to
        // check: the only value this `img.src` may ever carry is a blob
        // URL the browser just minted for a File the visitor picked. The
        // guard is what makes that an invariant rather than a reading of
        // the three lines above, and it is why CodeQL flagged this
        // assignment HIGH — an `src` is a navigable sink, and « it came
        // from our own code » is not an argument that survives the next
        // caller. A browser that answered anything else gets the text
        // fallback, same as an undecodable image.
        if (url.indexOf('blob:') !== 0) {
            URL.revokeObjectURL(url);

            return null;
        }

        var held = heldUrls.get(zone) || [];
        held.push(url);
        heldUrls.set(zone, held);

        var img = document.createElement('img');
        img.className = PREVIEW_CLASS + ' rounded border me-2 mb-1';
        // The name, not "Aperçu": a screen reader reading "image" twice
        // over tells nobody which photo this is, and the visible name
        // below is for the sighted reader only.
        img.alt = file.name;
        // A declared image whose bytes are not one — a renamed file, a
        // format this engine cannot decode — leaves the name standing
        // rather than a broken frame, and gives the URL straight back.
        img.onerror = function () {
            img.remove();
            URL.revokeObjectURL(url);
        };
        img.src = url;

        return img;
    }

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

        // Whatever the previous selection held goes back now, before
        // anything replaces it: this runs on every pick, including the
        // one that clears the zone.
        releaseUrls(zone);

        if (!files || files.length === 0) {
            target.textContent = '';
            return;
        }

        // Array.from, not for-of: what reaches here is a FileList — or, in
        // the tests, a FileList-alike — and an array-LIKE is not an
        // iterable. Reading it by length and index is the whole contract.
        var picked = Array.from(files);
        var names = picked.map(function (file) { return file.name; });

        // Not "1 fichier sélectionné": the name is what tells somebody
        // they picked the holiday snap rather than the invoice. It stays
        // even under a thumbnail — the picture says which photo, the name
        // says which file, and a screen reader gets the second one.
        target.textContent = names.join(', ');

        if (!wantsPreview(zone)) {
            return;
        }

        var thumbs = picked
            .map(function (file) { return thumbnail(zone, file); })
            .filter(function (img) { return img !== null; });

        if (thumbs.length === 0) {
            return;
        }

        // Above the name rather than beside it: on a phone a thumbnail and
        // a long filename on one line leave room for neither.
        var strip = document.createElement('span');
        strip.className = 'd-block mb-1';
        thumbs.forEach(function (img) {
            strip.appendChild(img);
        });
        target.insertBefore(strip, target.firstChild);
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
                        describe(zone, input.files?.length ? input.files : files);
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
