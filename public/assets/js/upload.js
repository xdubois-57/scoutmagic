/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

(function () {
    var dropZone = document.getElementById('drop-zone');
    var fileInput = /** @type {HTMLInputElement | null} */ (document.getElementById('file-input'));
    var cameraInput = /** @type {HTMLInputElement | null} */ (document.getElementById('camera-input'));
    var previewContainer = document.getElementById('preview-container');
    var previewImg = /** @type {HTMLImageElement | null} */ (document.getElementById('preview-img'));
    var previewName = document.getElementById('preview-name');
    var uploadBtn = /** @type {HTMLButtonElement | null} */ (document.getElementById('upload-btn'));
    var uploadForm = document.getElementById('upload-form');

    if (!dropZone || !fileInput) return;

    // Client-side downscale before POSTing — only for the core photo
    // contexts below. The gallery module has its own upload path, its own
    // thumbnail chain, and much higher limits (30 MB photos, 2 GB videos)
    // — never touched here, this page isn't even reachable from that
    // module's own upload flow.
    //
    // Each context's budget is the size of the derivative the server
    // actually keeps (Core\Photo\ImageVariantProcessor), not one global
    // number: uploading more pixels than the server will ever store is
    // time the phone spends for nothing, and that gap is enormous for an
    // avatar.
    //
    //   edge            which edge maxPixels caps — 'long' for an image
    //                   shown whole, 'short' for one the server
    //                   centre-crops to a square (see member_photo below)
    //   maxPixels       cap for that edge, in pixels
    //   quality         WebP quality for the re-encode
    //   skipBelowBytes  a file under this size AND already within the
    //                   pixel budget is POSTed untouched; 0 means always
    //                   re-encode

    // Shown whole, through the `md` variant (1024px longest edge). The
    // margin over 1024 is deliberate — it keeps the stored original good
    // enough to regenerate a larger variant from later — and matches the
    // server-side floor this step exists to keep uploads comfortably
    // under (Core\Http\Controller\UploadController).
    var CONTENT_BUDGET = { edge: 'long', maxPixels: 2400, quality: 0.85, skipBelowBytes: 5 * 1024 * 1024 };

    // Avatars (person_avatar()/member_photo() — a member's photo, an
    // identified account's photo). The server keeps ONLY a 192×192
    // centre-cropped square of these (ImageVariantProcessor::processThumb)
    // and nothing ever serves the original back — see the note in
    // Core\Photo\AccountPhotoService::forget(). CONTENT_BUDGET sent
    // 2400px of a picture the server reduces to 192 — twenty times the
    // pixels it keeps — and let anything under 5 MB and 2400px through
    // untouched, which is why picking a photo felt slow: the phone paid
    // for the big encode AND for the upload. Measured in Chromium on a
    // 3024×4032 source: 274 KB in 859 ms before, 74 KB in 345 ms after,
    // and a real phone is far slower than a desktop at both.
    //
    // 'short' rather than 'long' because of that centre crop: the square
    // it keeps is as wide as the SHORT edge, so that is the edge which
    // has to stay at 2× the 192px thumb. Budgeting the long one would
    // starve the crop on a wide source — a 4000×1000 photo would become
    // 512×128 and the thumb would be an upscale.
    var AVATAR_BUDGET = { edge: 'short', maxPixels: 512, quality: 0.8, skipBelowBytes: 0 };

    var DOWNSCALE_BUDGETS = {
        member_photo: AVATAR_BUDGET,
        account_photo: AVATAR_BUDGET,
        section_photo: CONTENT_BUDGET,
        editable_image: CONTENT_BUDGET,
        age_branch_logo: CONTENT_BUDGET,
        unit_logo: CONTENT_BUDGET
    };

    var contextInput = /** @type {HTMLInputElement | null} */ (uploadForm ? uploadForm.querySelector('input[name="context"]') : null);
    var uploadContext = contextInput ? contextInput.value : '';
    var budget = Object.prototype.hasOwnProperty.call(DOWNSCALE_BUDGETS, uploadContext)
        ? DOWNSCALE_BUDGETS[uploadContext]
        : null;
    // Object URL currently shown in the preview <img>, revoked as soon as
    // the next one replaces it — picking three files in a row must not
    // pin three decoded images in memory.
    var previewUrl = null;

    function handleFile(file) {
        if (!file) return;

        if (shouldConsiderDownscale(file)) {
            uploadBtn.disabled = true;
            previewName.textContent = 'Préparation de l\'image…';
            previewContainer.classList.remove('d-none');

            downscaleToWebp(file).then(applyFile).catch(function () {
                // A canvas/encode failure must never block the upload —
                // fall back to the original file untouched.
                applyFile(file);
            });
            return;
        }

        applyFile(file);
    }

    function shouldConsiderDownscale(file) {
        return budget !== null
            && typeof HTMLCanvasElement !== 'undefined'
            && typeof file.type === 'string'
            && file.type.indexOf('image/') === 0;
    }

    /**
     * Resolves to the original file unchanged when it's already within
     * both the size and pixel budget of the upload's context, otherwise to
     * a re-encoded WebP File within that budget. Alpha is preserved
     * throughout — the canvas defaults to transparent and drawImage()
     * copies the source's alpha channel as-is.
     */
    function downscaleToWebp(file) {
        return loadImage(file).then(function (img) {
            var width = img.naturalWidth || img.width;
            var height = img.naturalHeight || img.height;
            var budgetedEdge = budget.edge === 'short'
                ? Math.min(width, height)
                : Math.max(width, height);

            if (file.size < budget.skipBelowBytes && budgetedEdge <= budget.maxPixels) {
                if (typeof ImageBitmap !== 'undefined' && img instanceof ImageBitmap) {
                    img.close();
                }
                return file;
            }

            var scale = Math.min(1, budget.maxPixels / budgetedEdge);
            var targetWidth = Math.max(1, Math.round(width * scale));
            var targetHeight = Math.max(1, Math.round(height * scale));

            var canvas = document.createElement('canvas');
            canvas.width = targetWidth;
            canvas.height = targetHeight;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                throw new Error('2D canvas context unavailable');
            }
            ctx.drawImage(img, 0, 0, targetWidth, targetHeight);
            if (typeof ImageBitmap !== 'undefined' && img instanceof ImageBitmap) {
                img.close();
            }

            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('WebP encoding failed'));
                        return;
                    }
                    var name = file.name.replace(/\.[^.]+$/, '') + '.webp';
                    resolve(new File([blob], name, { type: 'image/webp' }));
                }, 'image/webp', budget.quality);
            });
        });
    }

    /**
     * Decodes the file into whatever drawImage()-compatible source
     * explicitly guarantees EXIF orientation is applied. createImageBitmap
     * with imageOrientation:'from-image' is preferred (its default is
     * actually 'none' — unlike an <img> element, an unconfigured
     * createImageBitmap call would silently draw a sideways phone photo)
     * because it makes that guarantee explicit rather than relying on a
     * given browser/WebView's own <img>-decode default, which is where a
     * real inconsistency would otherwise hide: most engines apply EXIF
     * orientation by default today, but this downscale path is the one
     * place a phone photo's pixels get baked into a final file before the
     * server ever sees them, so it shouldn't depend on that. Falls back to
     * the plain <img> element for engines without createImageBitmap (or
     * without this option) — same as those engines were already doing
     * before this function existed.
     */
    function loadImage(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
                return loadImageElement(file);
            });
        }
        return loadImageElement(file);
    }

    function loadImageElement(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                resolve(img);
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('Could not load image'));
            };
            img.src = url;
        });
    }

    /**
     * Final step for any file, downscaled or not: shows the preview and
     * points the actual submitted input ("file") at it — whichever
     * source it came from (picker, drop, or camera capture), since the
     * server only ever reads one of "file"/"file_camera" per request.
     */
    function applyFile(file) {
        if (file.type.indexOf('image/') === 0) {
            // An object URL, not a FileReader data URL: the reader copies
            // the whole file into a base64 string (a third bigger again)
            // before anything appears, which on a phone photo is a visible
            // pause between picking the file and seeing it — the other
            // half of why this page felt slow.
            if (previewUrl !== null) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);
            previewImg.src = previewUrl;
            previewContainer.classList.remove('d-none');
        }

        previewName.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' Ko)';
        previewContainer.classList.remove('d-none');

        var dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;

        uploadBtn.disabled = false;
    }

    // The drop half is the shared zone (public/assets/js/drop-zone.js).
    // pickOnClick:false because this screen's two controls are <label>s
    // that already open their own inputs — wiring the zone's click on top
    // would open a picker the visitor did not ask for.
    window.ScoutMagicDropZone.bind(dropZone, function (files) {
        if (files[0]) {
            handleFile(files[0]);
        }
    }, { pickOnClick: false });

    fileInput.addEventListener('change', function () {
        if (fileInput.files[0]) handleFile(fileInput.files[0]);
    });

    if (cameraInput) {
        cameraInput.addEventListener('change', function () {
            if (cameraInput.files[0]) {
                handleFile(cameraInput.files[0]);
            }
        });
    }
})();
