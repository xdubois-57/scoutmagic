/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// window.ScoutMagicPhotoReduce — a phone photograph made small in the
// browser before it is sent (#989, lot B): 2 400 px on its longest side at
// most, re-encoded as a JPEG at 0.85, named `.jpg`.
//
// Why here and not only on the server. `Core\Image\PhotoNormalizer` does
// exactly the same on arrival, and it remains the guarantee: a browser can
// fail, or be bypassed. What this step buys is the upload itself — a 12 Mpx
// photo of a broken window weighs 4 to 6 MB, five of them leave a phone on
// a hall's patchy signal for a long time, and the server would throw away
// most of those pixels anyway.
//
// Re-encoding through a canvas copies pixels, never metadata: the EXIF
// block, GPS position included, cannot survive it. The orientation is
// asked for explicitly (`imageOrientation: 'from-image'`) so that what is
// baked in is the picture the person saw, not a sideways one — see
// loadImage() in upload.js, whose approach this repeats.
//
// **Any failure gives the original file back.** An engine that cannot
// decode the format (HEIC outside Safari), a canvas that cannot encode, a
// browser without createImageBitmap or canvas: the photo is sent as it was
// picked and the server decides — it converts or refuses, never stores it
// as it came. A reduction must never be the reason a photo is lost.
//
// A classic script rather than a module: a page loads it with asset(),
// which versions its URL, and a module's own `import` of it could not be.
(function () {
    'use strict';

    /** The longest side kept, in pixels — PhotoNormalizer's own bound. */
    var MAX_EDGE = 2400;

    /** JPEG quality, the normaliser's 85 %. */
    var QUALITY = 0.85;

    /**
     * Whether this is worth trying. A HEIC photo often arrives with no
     * type at all outside Safari, so its extension counts too.
     *
     * @param {File} file
     * @returns {boolean}
     */
    function isImage(file) {
        if (typeof file.type === 'string' && file.type.indexOf('image/') === 0) {
            return true;
        }

        return /\.(heic|heif)$/i.test(file.name || '');
    }

    /**
     * @param {File} file
     * @returns {Promise<ImageBitmap|HTMLImageElement>}
     */
    function loadImage(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
                return loadImageElement(file);
            });
        }

        return loadImageElement(file);
    }

    /**
     * The plain <img> decode, for an engine without createImageBitmap or
     * without its orientation option. Current engines apply the EXIF
     * orientation to an <img> by default.
     *
     * @param {File} file
     * @returns {Promise<HTMLImageElement>}
     */
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
     * The size a picture of this size is drawn at.
     *
     * @param {number} width
     * @param {number} height
     * @returns {{ width: number, height: number }}
     */
    function targetSize(width, height) {
        var scale = Math.min(1, MAX_EDGE / Math.max(width, height, 1));

        return {
            width: Math.max(1, Math.round(width * scale)),
            height: Math.max(1, Math.round(height * scale))
        };
    }

    /**
     * @param {string} name
     * @returns {string}
     */
    function jpegName(name) {
        var base = (name || '').replace(/\.[^.]+$/, '');

        return (base === '' ? 'photo' : base) + '.jpg';
    }

    /**
     * @param {ImageBitmap|HTMLImageElement} img
     * @returns {void}
     */
    function release(img) {
        if (typeof ImageBitmap !== 'undefined' && img instanceof ImageBitmap) {
            img.close();
        }
    }

    /**
     * Resolves to a JPEG of the photo, 2 400 px on its longest side at
     * most — or to the very file it was given when anything along the way
     * fails. Never rejects.
     *
     * @param {File} file
     * @returns {Promise<File>}
     */
    function reducePhoto(file) {
        if (!file || !isImage(file) || typeof HTMLCanvasElement === 'undefined') {
            return Promise.resolve(file);
        }

        return loadImage(file).then(function (img) {
            var width = /** @type {any} */ (img).naturalWidth || img.width;
            var height = /** @type {any} */ (img).naturalHeight || img.height;
            var size = targetSize(width, height);

            var canvas = document.createElement('canvas');
            canvas.width = size.width;
            canvas.height = size.height;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                release(img);
                throw new Error('2D canvas context unavailable');
            }
            // A JPEG has no transparency: a transparent PNG would come out
            // black where it was clear.
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, size.width, size.height);
            ctx.drawImage(img, 0, 0, size.width, size.height);
            release(img);

            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('JPEG encoding failed'));
                        return;
                    }
                    resolve(new File([blob], jpegName(file.name), { type: 'image/jpeg' }));
                }, 'image/jpeg', QUALITY);
            });
        }).catch(function () {
            return file;
        });
    }

    window.ScoutMagicPhotoReduce = {
        MAX_EDGE: MAX_EDGE,
        QUALITY: QUALITY,
        reducePhoto: reducePhoto,
        targetSize: targetSize,
        jpegName: jpegName
    };
})();
