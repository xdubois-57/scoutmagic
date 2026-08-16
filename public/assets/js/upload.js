/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

(function () {
    var dropZone = document.getElementById('drop-zone');
    var fileInput = document.getElementById('file-input');
    var cameraInput = document.getElementById('camera-input');
    var previewContainer = document.getElementById('preview-container');
    var previewImg = document.getElementById('preview-img');
    var previewName = document.getElementById('preview-name');
    var uploadBtn = document.getElementById('upload-btn');
    var uploadForm = document.getElementById('upload-form');

    if (!dropZone || !fileInput) return;

    // Client-side downscale before POSTing — only for the core photo
    // contexts (member_photo, section_photo, editable_image,
    // age_branch_logo, unit_logo). The gallery module has its own upload
    // path, its own thumbnail chain, and much higher limits (30 MB
    // photos, 2 GB videos) — never touched here, this page isn't even
    // reachable from that module's own upload flow.
    var DOWNSCALE_CONTEXTS = ['member_photo', 'section_photo', 'editable_image', 'age_branch_logo', 'unit_logo'];
    var MAX_DIMENSION = 2400;
    var WEBP_QUALITY = 0.85;
    // A file already under this size AND within MAX_DIMENSION needs no
    // re-encoding — matches the server-side floor this step exists to
    // keep uploads comfortably under (Core\Http\Controller\UploadController).
    var SKIP_THRESHOLD_BYTES = 5 * 1024 * 1024;

    var contextInput = uploadForm ? uploadForm.querySelector('input[name="context"]') : null;
    var uploadContext = contextInput ? contextInput.value : '';

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
        return DOWNSCALE_CONTEXTS.indexOf(uploadContext) !== -1
            && typeof HTMLCanvasElement !== 'undefined'
            && typeof file.type === 'string'
            && file.type.indexOf('image/') === 0;
    }

    /**
     * Resolves to the original file unchanged when it's already within
     * both the size and dimension budget, otherwise to a re-encoded WebP
     * File whose longest edge is at most MAX_DIMENSION. Alpha is
     * preserved throughout — the canvas defaults to transparent and
     * drawImage() copies the source's alpha channel as-is.
     */
    function downscaleToWebp(file) {
        return loadImage(file).then(function (img) {
            var width = img.naturalWidth || img.width;
            var height = img.naturalHeight || img.height;

            if (file.size < SKIP_THRESHOLD_BYTES && Math.max(width, height) <= MAX_DIMENSION) {
                return file;
            }

            var scale = Math.min(1, MAX_DIMENSION / Math.max(width, height));
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

            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('WebP encoding failed'));
                        return;
                    }
                    var name = file.name.replace(/\.[^.]+$/, '') + '.webp';
                    resolve(new File([blob], name, { type: 'image/webp' }));
                }, 'image/webp', WEBP_QUALITY);
            });
        });
    }

    function loadImage(file) {
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
            var reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewContainer.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        }

        previewName.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' Ko)';
        previewContainer.classList.remove('d-none');

        var dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;

        uploadBtn.disabled = false;
    }

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.classList.add('border-primary');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.classList.remove('border-primary');
        });
    });

    dropZone.addEventListener('drop', function (e) {
        var file = e.dataTransfer.files[0];
        if (file) {
            handleFile(file);
        }
    });

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
