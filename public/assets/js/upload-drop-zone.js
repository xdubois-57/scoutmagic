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
// The preview is local: the picked File is DECODED in the browser and its
// pixels drawn into a <canvas>. Selecting a file uploads nothing — that is
// the form's own POST, later, when the visitor presses the button.
//
// Deliberately NOT `img.src = URL.createObjectURL(file)`, which is the
// shape upload.js uses and the shape this file was written with first.
// Here the File is reached through an element this module looked up from a
// DOM attribute (`data-drop-zone-for` names the input), so the object URL
// derived from it is DOM-derived text, and CodeQL rates the resulting
// `src` assignment a HIGH « DOM text reinterpreted as HTML ». Checking the
// string at the sink did not answer it and should not have been expected
// to: the honest answer is to stop putting a string there. Decoded pixels
// leave no URL to assign, none to give back, and no sink to guard.

(function () {
    var PREVIEW_CLASS = 'drop-zone-preview';

    /**
     * The longest side of a thumbnail, in CSS pixels. Small on purpose:
     * this sits above a filename inside a form's help text, and its job is
     * « which photo is this », not « look at this photo ».
     */
    var PREVIEW_SIZE = 96;

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
     * One thumbnail for a picked image, or null for anything this browser
     * will not decode — the caller then has only the name to show, which
     * is the right answer rather than a broken frame.
     *
     * Returned EMPTY and `hidden`, and filled in when the decode resolves:
     * the element has to exist straight away so the thumbnails appear in
     * the order the files were picked, and it stays hidden until it has
     * pixels because a bordered empty canvas is a grey box, and a file
     * whose bytes are not an image would leave that box standing.
     *
     * `imageOrientation: 'from-image'` for the reason upload.js writes out
     * at length: createImageBitmap's own default is 'none', so an
     * unconfigured call draws a sideways phone photo.
     *
     * A rejected decode is the same outcome `img.onerror` used to give,
     * with one race fewer: there is no element in the document yet that
     * has to be taken back out from under a load event.
     *
     * No fallback to an <img> for an engine without createImageBitmap:
     * that is exactly the sink this file no longer has (see the top of the
     * file). Those engines show the filename, as they did before #756.
     *
     * @param {File} file
     * @returns {HTMLCanvasElement|null}
     */
    function thumbnail(file) {
        if (typeof file.type !== 'string' || !file.type.startsWith('image/')) {
            return null;
        }
        if (typeof createImageBitmap !== 'function') {
            return null;
        }

        var canvas = document.createElement('canvas');
        canvas.className = PREVIEW_CLASS + ' rounded border me-2 mb-1';
        canvas.hidden = true;
        // Decorative: the filename beside it is the accessible answer, and
        // a screen reader announcing "image" over a canvas with no name
        // tells nobody which photo this is.
        canvas.setAttribute('aria-hidden', 'true');

        createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
            draw(canvas, bitmap);
        }, function () {
            canvas.remove();
        });

        return canvas;
    }

    /**
     * @param {HTMLCanvasElement} canvas
     * @param {ImageBitmap} bitmap
     * @returns {void}
     */
    function draw(canvas, bitmap) {
        var longest = Math.max(bitmap.width, bitmap.height);
        // Never enlarged: a 32-pixel icon blown up to 96 is a blurry mess,
        // and `min` is what keeps the thumbnail at the file's own size
        // when the file is smaller than the box.
        var scale = longest > 0 ? Math.min(1, PREVIEW_SIZE / longest) : 1;
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));

        var context = canvas.getContext('2d');
        if (context === null) {
            // No 2D context to paint into: nothing to show, and an empty
            // bordered box would be worse than the name alone.
            canvas.remove();
        } else {
            context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            canvas.hidden = false;
        }

        if (typeof bitmap.close === 'function') {
            // The decoded pixels are in the canvas now; the bitmap itself
            // is several megabytes of phone photo with nothing left to do.
            bitmap.close();
        }
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

        // Nothing to release first: the line above already replaced every
        // child of `target`, the previous selection's canvases among them,
        // and a decode still in flight for one of them resolves onto an
        // element that is no longer in the document — which is the whole
        // reason there is no longer a list of object URLs to give back.
        var thumbs = picked
            .map(function (file) { return thumbnail(file); })
            .filter(function (canvas) { return canvas !== null; });

        if (thumbs.length === 0) {
            return;
        }

        // Above the name rather than beside it: on a phone a thumbnail and
        // a long filename on one line leave room for neither.
        var strip = document.createElement('span');
        strip.className = 'd-block mb-1';
        thumbs.forEach(function (canvas) {
            strip.appendChild(canvas);
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
