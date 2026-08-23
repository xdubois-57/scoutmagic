/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance module receipt upload form
// (modules/finance/views/receipts/form.html.twig), in both the shapes that
// one template renders: the multi-file drop zone of « Nouveau reçu » (pick
// or drag up to ten receipts, review the list, remove one) and the single
// file input of « Remplacer le reçu ». Everything either shape does before
// the browser posts the form is here: client-side image downscaling, so a
// 4 MB phone photo does not have to travel — and does not have to be
// refused by the server's 15 MB limit — as one.
//
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly
// (tests/js/finance-receipt-form.test.js). The template used to select
// between the two shapes with a Twig {% if replace_id %} *inside* the
// script; the file reads the DOM instead — #receipt-file is the replace
// form, #drop-zone the multi-file one — which is the same decision, made
// where a test can reach it.
//
// There is no fetch() here at all, and nothing to route through the
// ScoutMagicApi JSON envelope: the upload is the form's own native
// multipart POST, which is also why the file rewrites the <input>'s
// FileList (through DataTransfer) rather than building a body of its own.
// The submit button rides api.withDisabled() for the duration of the
// compression, so a second click cannot start a second submit of the same
// receipts while the first one is still resizing.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('receipt-form'));
    var statusEl = document.getElementById('receipt-file-status');
    // A no-op on any page that loads this file without the form — the
    // « Aucun compte visible pour votre rôle. » empty state renders the
    // page header and nothing else.
    if (!form || !statusEl) {
        return;
    }

    var MAX_WIDTH = 1600;
    var JPEG_QUALITY = 0.75;
    var MAX_FILES = 10;

    var api = window.ScoutMagicApi;
    var submitBtn = /** @type {HTMLButtonElement|null} */ (form.querySelector('button[type="submit"]'));

    // Downscaling needs all three, and so does rewriting the input's
    // FileList afterwards. Where any is missing the form submits the files
    // exactly as they were picked — the server accepts them either way.
    var supportsResize = !!(window.FileReader && window.HTMLCanvasElement && window.DataTransfer);

    /**
     * One image file, re-encoded as a JPEG at most MAX_WIDTH wide (aspect
     * ratio kept; a smaller image is never upscaled). Rejects rather than
     * resolving a half-processed blob — every caller falls back to the
     * original file on a rejection.
     *
     * @param {File} file
     * @returns {Promise<Blob>}
     */
    function resizeImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                const img = new Image();
                img.onload = () => {
                    let width = img.width;
                    let height = img.height;
                    if (width > MAX_WIDTH) {
                        height = Math.round(height * (MAX_WIDTH / width));
                        width = MAX_WIDTH;
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    if (!ctx) {
                        reject(new Error('Canvas context unavailable'));
                        return;
                    }
                    ctx.drawImage(img, 0, 0, width, height);
                    canvas.toBlob((blob) => {
                        if (blob) {
                            resolve(blob);
                        } else {
                            reject(new Error('toBlob failed'));
                        }
                    }, 'image/jpeg', JPEG_QUALITY);
                };
                img.onerror = () => reject(new Error('Image load failed'));
                img.src = /** @type {string} */ (reader.result);
            };
            reader.onerror = () => reject(new Error('FileReader failed'));
            reader.readAsDataURL(file);
        });
    }

    /**
     * The resized counterpart of one image file: same base name, .jpg
     * extension, JPEG type.
     *
     * @param {File} file
     * @param {Blob} blob
     * @returns {File}
     */
    function toJpegFile(file, blob) {
        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
    }

    var singleInput = /** @type {HTMLInputElement|null} */ (document.getElementById('receipt-file'));
    var dropZone = document.getElementById('drop-zone');

    if (singleInput) {
        // --- « Remplacer le reçu » : one file, one input ---
        form.addEventListener('submit', async (e) => {
            const file = singleInput.files[0];
            if (!file || !file.type.startsWith('image/') || !supportsResize) {
                return; // native submit proceeds — non-image, or no client-side resize support
            }

            e.preventDefault();
            statusEl.textContent = 'Compression de l\'image…';

            await api.withDisabled(submitBtn, async () => {
                try {
                    const resizedBlob = await resizeImage(file);
                    const transfer = new DataTransfer();
                    transfer.items.add(toJpegFile(file, resizedBlob));
                    singleInput.files = transfer.files;
                    statusEl.textContent = 'Image compressée (' + Math.round(resizedBlob.size / 1024) + ' Ko).';
                } catch (err) {
                    // The original file is still in the input: submit it.
                    statusEl.textContent = '';
                }
            });

            form.submit();
        });
    } else if (dropZone) {
        // --- « Nouveau reçu » : up to MAX_FILES files, drag and drop ---
        const filesInput = /** @type {HTMLInputElement} */ (document.getElementById('receipt-files'));
        const filesList = document.getElementById('receipt-files-list');
        /** @type {File[]} */
        let selectedFiles = [];

        function renderFilesList() {
            filesList.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const li = document.createElement('li');
                li.className = 'd-flex align-items-center gap-2';
                const nameSpan = document.createElement('span');
                nameSpan.textContent = file.name;
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-link text-danger p-0';
                removeBtn.textContent = 'Retirer';
                removeBtn.addEventListener('click', () => {
                    selectedFiles.splice(index, 1);
                    syncInputAndRender();
                });
                li.appendChild(nameSpan);
                li.appendChild(removeBtn);
                filesList.appendChild(li);
            });
        }

        // The <input> is what the browser actually posts, so the selection
        // kept here has to be written back into it on every change —
        // through a DataTransfer, the only way to build a FileList.
        function syncInputAndRender() {
            const transfer = new DataTransfer();
            selectedFiles.forEach(file => transfer.items.add(file));
            filesInput.files = transfer.files;
            renderFilesList();
        }

        /** @param {FileList|File[]} fileList */
        function addFiles(fileList) {
            const incoming = Array.from(fileList);
            let combined = selectedFiles.concat(incoming);
            if (combined.length > MAX_FILES) {
                statusEl.textContent = 'Maximum ' + MAX_FILES + ' reçus à la fois — les suivants ont été ignorés.';
                combined = combined.slice(0, MAX_FILES);
            } else {
                statusEl.textContent = '';
            }
            selectedFiles = combined;
            syncInputAndRender();
        }

        // Mouse only. The keyboard path is the file input's own: it is focusable
        // (visually-hidden, not d-none) and opens its picker on Enter and Space with
        // no script. Guarded against the input's own click bubbling back up here,
        // which would reopen the picker the moment it closed.
        // Drag, drop, click-to-pick and the highlight are the shared zone
        // (public/assets/js/drop-zone.js) — including the guard that stops
        // a click on the hidden input from re-opening the picker.
        window.ScoutMagicDropZone.bind(dropZone, addFiles, { input: filesInput });

        form.addEventListener('submit', async (e) => {
            if (selectedFiles.length === 0) {
                e.preventDefault();
                statusEl.textContent = 'Veuillez sélectionner au moins un reçu.';
                return;
            }

            if (!supportsResize) {
                return; // native submit proceeds
            }

            const hasImages = selectedFiles.some(file => file.type.startsWith('image/'));
            if (!hasImages) {
                return;
            }

            e.preventDefault();
            statusEl.textContent = 'Compression des images…';

            await api.withDisabled(submitBtn, async () => {
                try {
                    const processed = [];
                    for (const file of selectedFiles) {
                        if (!file.type.startsWith('image/')) {
                            processed.push(file);
                            continue;
                        }
                        try {
                            const resizedBlob = await resizeImage(file);
                            processed.push(toJpegFile(file, resizedBlob));
                        } catch (err) {
                            processed.push(file); // fall back to the original file
                        }
                    }
                    selectedFiles = processed;
                    syncInputAndRender();
                    statusEl.textContent = '';
                } catch (err) {
                    statusEl.textContent = '';
                }
            });

            form.submit();
        });
    }
})();
