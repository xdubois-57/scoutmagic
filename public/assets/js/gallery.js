/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Gallery module front-end: lightbox/player (album.html.twig), drag-and-drop
// batch upload + media reorder/delete/cover (album_form.html.twig). Pure JS,
// no external library — same IIFE/var/fetch style as list-editor.js.
(function () {
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    // Always resolves: every caller below branches on data.success, so a
    // dropped connection or a non-JSON error page has to come back as a
    // failure object rather than an unhandled rejection that silently does
    // nothing at all.
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({}, body, { _csrf_token: csrf() }))
        }).then(function (res) {
            return res.json().catch(function () {
                return { success: false, error: 'Réponse inattendue du serveur.' };
            });
        }).catch(function () {
            return { success: false, error: 'Erreur réseau.' };
        });
    }

    // ------------------------------------------------------------------
    // Lightbox (album.html.twig)
    // ------------------------------------------------------------------
    (function initLightbox() {
        var triggers = Array.from(document.querySelectorAll('.gallery-lightbox-trigger'));
        var box = document.getElementById('gallery-lightbox');
        if (!triggers.length || !box) return;

        var imageEl = document.getElementById('gallery-lightbox-image');
        var videoEl = document.getElementById('gallery-lightbox-video');
        var closeBtn = document.getElementById('gallery-lightbox-close');
        var prevBtn = document.getElementById('gallery-lightbox-prev');
        var nextBtn = document.getElementById('gallery-lightbox-next');
        var hqBtn = document.getElementById('gallery-lightbox-hq');
        var downloadBtn = document.getElementById('gallery-lightbox-download');

        var items = [];
        var currentIndex = -1;

        function preload(index) {
            var item = items[index];
            if (item && item.type === 'photo') {
                var img = new Image();
                img.src = item.mediumUrl;
            }
        }

        function show(index) {
            if (index < 0 || index >= items.length) return;
            currentIndex = index;
            var item = items[index];

            videoEl.pause();
            videoEl.removeAttribute('src');
            videoEl.load();

            if (item.type === 'video') {
                imageEl.classList.add('d-none');
                videoEl.src = item.mediumUrl;
                videoEl.classList.remove('d-none');
                hqBtn.classList.add('d-none');
            } else {
                videoEl.classList.add('d-none');
                imageEl.src = item.mediumUrl;
                imageEl.classList.remove('d-none');
                hqBtn.classList.toggle('d-none', !item.largeUrl);
                hqBtn.dataset.largeUrl = item.largeUrl || '';
            }

            // This is the "large" rendition, not a pristine original — and the
            // download attribute is ignored cross-origin anyway (S3/CDN), so
            // the link opens in a new tab there rather than appearing broken.
            if (item.largeUrl) {
                downloadBtn.href = item.largeUrl;
                downloadBtn.classList.remove('d-none');
            } else {
                downloadBtn.classList.add('d-none');
            }

            prevBtn.classList.toggle('d-none', index === 0);
            nextBtn.classList.toggle('d-none', index === items.length - 1);

            preload(index - 1);
            preload(index + 1);
        }

        function open(index) {
            show(index);
            box.classList.remove('d-none');
            document.body.style.overflow = 'hidden';
        }

        function close() {
            box.classList.add('d-none');
            document.body.style.overflow = '';
            videoEl.pause();
            videoEl.removeAttribute('src');
            videoEl.load();
        }

        // Only triggers that actually have a rendition to show get an index and
        // a click handler. Mapping every trigger onto a filtered list by URL
        // meant a still-processing (or failed) thumbnail resolved to index -1,
        // and open(-1) un-hid the overlay anyway: a fullscreen black screen
        // with nothing in it.
        triggers.forEach(function (btn) {
            var mediumUrl = btn.dataset.mediumUrl;
            if (!mediumUrl) {
                btn.setAttribute('aria-disabled', 'true');
                return;
            }
            var index = items.length;
            items.push({
                type: btn.dataset.type,
                mediumUrl: mediumUrl,
                largeUrl: btn.dataset.largeUrl
            });
            btn.addEventListener('click', function () { open(index); });
        });
        if (!items.length) return;

        closeBtn.addEventListener('click', close);
        box.addEventListener('click', function (e) { if (e.target === box) close(); });
        prevBtn.addEventListener('click', function () { show(currentIndex - 1); });
        nextBtn.addEventListener('click', function () { show(currentIndex + 1); });
        hqBtn.addEventListener('click', function () {
            if (hqBtn.dataset.largeUrl) imageEl.src = hqBtn.dataset.largeUrl;
        });

        document.addEventListener('keydown', function (e) {
            if (box.classList.contains('d-none')) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') show(currentIndex - 1);
            else if (e.key === 'ArrowRight') show(currentIndex + 1);
        });

        // Touch swipe
        var touchStartX = null;
        box.addEventListener('touchstart', function (e) {
            touchStartX = e.changedTouches[0].clientX;
        }, { passive: true });
        box.addEventListener('touchend', function (e) {
            if (touchStartX === null) return;
            var dx = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(dx) > 50) show(currentIndex + (dx < 0 ? 1 : -1));
            touchStartX = null;
        }, { passive: true });
    })();

    // ------------------------------------------------------------------
    // Upload zone (album_form.html.twig) — parallel batches of 5 XHR
    // requests, one file per request (server route accepts a single file).
    // ------------------------------------------------------------------
    (function initUpload() {
        var zone = document.getElementById('gallery-upload-zone');
        var input = document.getElementById('gallery-upload-input');
        var progressWrap = document.getElementById('gallery-upload-progress');
        var progressBar = document.getElementById('gallery-upload-progress-bar');
        var progressLabel = document.getElementById('gallery-upload-progress-label');
        if (!zone || !input) return;

        var uploadUrl = zone.dataset.uploadUrl;
        var CONCURRENCY = 5;

        function uploadOne(file) {
            return new Promise(function (resolve) {
                var xhr = new XMLHttpRequest();
                var formData = new FormData();
                formData.append('file', file);
                formData.append('_csrf_token', csrf());
                xhr.open('POST', uploadUrl, true);
                xhr.onload = function () {
                    var data = {};
                    try { data = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
                    resolve({ file: file, success: xhr.status === 200 && data.success, error: data.error });
                };
                xhr.onerror = function () { resolve({ file: file, success: false, error: 'Erreur réseau.' }); };
                xhr.send(formData);
            });
        }

        function uploadAll(files) {
            var queue = Array.from(files);
            var total = queue.length;
            var done = 0;
            var errors = [];

            progressWrap.classList.remove('d-none');
            progressBar.style.width = '0%';
            progressLabel.textContent = '0 / ' + total;

            function updateProgress() {
                var pct = Math.round((done / total) * 100);
                progressBar.style.width = pct + '%';
                progressLabel.textContent = done + ' / ' + total;
            }

            function worker() {
                var file = queue.shift();
                if (!file) return Promise.resolve();
                return uploadOne(file).then(function (result) {
                    done++;
                    if (!result.success) errors.push((result.file.name || 'fichier') + ' : ' + (result.error || 'échec'));
                    updateProgress();
                    return worker();
                });
            }

            var workers = [];
            for (var i = 0; i < Math.min(CONCURRENCY, total); i++) workers.push(worker());

            return Promise.all(workers).then(function () {
                if (errors.length) alert('Certains fichiers n\'ont pas pu être envoyés :\n' + errors.join('\n'));
                window.location.reload();
            });
        }

        function handleFiles(fileList) {
            if (!fileList || !fileList.length) return;
            uploadAll(fileList);
        }

        ['dragenter', 'dragover'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault();
                zone.classList.add('border-primary');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault();
                zone.classList.remove('border-primary');
            });
        });
        zone.addEventListener('drop', function (e) { handleFiles(e.dataTransfer.files); });
        zone.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () { handleFiles(input.files); });
    })();

    // ------------------------------------------------------------------
    // Existing-media grid (album_form.html.twig): desktop-only drag-and-drop
    // reorder, delete, set-cover.
    // ------------------------------------------------------------------
    (function initMediaGrid() {
        var grid = document.getElementById('gallery-existing-media');
        if (!grid) return;

        var reorderUrl = grid.dataset.reorderUrl;
        var draggedItem = null;

        function persistOrder() {
            var ids = Array.from(grid.querySelectorAll('.gallery-media-item')).map(function (el) {
                return parseInt(el.dataset.mediaId, 10);
            });
            postJson(reorderUrl, { ordered_ids: ids }).then(function (data) {
                if (!data.success) alert(data.error || 'Erreur lors de la réorganisation.');
            });
        }

        grid.querySelectorAll('.gallery-media-item').forEach(function (item) {
            item.addEventListener('dragstart', function () {
                draggedItem = item;
                item.classList.add('opacity-50');
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('opacity-50');
                draggedItem = null;
                persistOrder();
            });
            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                if (!draggedItem || draggedItem === item) return;
                var rect = item.getBoundingClientRect();
                var after = (e.clientX - rect.left) > rect.width / 2;
                grid.insertBefore(draggedItem, after ? item.nextSibling : item);
            });
        });

        grid.querySelectorAll('.gallery-media-delete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Supprimer ce média ?')) return;
                var item = btn.closest('.gallery-media-item');
                postJson(btn.dataset.url, {}).then(function (data) {
                    if (data.success) {
                        item.remove();
                    } else {
                        alert(data.error || 'Erreur lors de la suppression.');
                    }
                });
            });
        });

        grid.querySelectorAll('.gallery-media-set-cover').forEach(function (btn) {
            btn.addEventListener('click', function () {
                postJson(btn.dataset.url, { media_id: parseInt(btn.dataset.mediaId, 10) }).then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.error || 'Erreur.');
                    }
                });
            });
        });
    })();

    // ------------------------------------------------------------------
    // Refresh OG metadata button (album_form.html.twig, external albums)
    // ------------------------------------------------------------------
    (function initRefreshOg() {
        var btn = document.getElementById('gallery-refresh-og');
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            postJson(btn.dataset.url, {}).then(function (data) {
                btn.disabled = false;
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Erreur lors de la récupération des métadonnées.');
                }
            });
        });
    })();

    // ------------------------------------------------------------------
    // Album type toggle (album_form.html.twig, create mode)
    // ------------------------------------------------------------------
    (function initTypeToggle() {
        var radios = document.querySelectorAll('input[name="type"]');
        var externalField = document.querySelector('.gallery-external-field');
        var externalInput = document.getElementById('album-external-url');
        var titleInput = document.getElementById('album-title');
        var titleHint = document.querySelector('.gallery-title-optional-hint');
        var localField = document.querySelector('.gallery-local-field');
        if (!radios.length || !externalField) return;

        function sync() {
            var checked = document.querySelector('input[name="type"]:checked');
            var isExternal = !!checked && checked.value === 'external';

            externalField.classList.toggle('d-none', !isExternal);
            if (externalInput) externalInput.required = isExternal;
            if (localField) localField.classList.toggle('d-none', isExternal);

            // The title is fetched from the link's own og:title when left
            // blank for an external album — never required upfront the
            // way a local album (no such fallback) still must be.
            if (titleInput) titleInput.required = !isExternal;
            if (titleHint) titleHint.classList.toggle('d-none', !isExternal);
        }
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    })();

    // ------------------------------------------------------------------
    // Delete album button (album_form.html.twig, edit mode)
    // ------------------------------------------------------------------
    (function initDeleteAlbum() {
        var btn = document.getElementById('gallery-delete-album');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!confirm('Supprimer définitivement cet album et tous ses médias ?')) return;
            postJson(btn.dataset.url, {}).then(function (data) {
                if (data.success) {
                    window.location.href = '/gallery/manage';
                } else {
                    alert(data.error || 'Erreur lors de la suppression.');
                }
            });
        });
    })();
})();
