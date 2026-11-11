/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The shared page of every data migration import
// (modules/data_migration/views/importer.html.twig).
//
// Two jobs, both on the page's own state, read from the
// `data-migration-page` island:
//
// - **the deposit** (step 1): the ZIP goes up in ~8 MB fragments through
//   chunked-upload.js. The upload id is kept in sessionStorage with the
//   file's name, size and date, so a page reloaded in the same tab asks the
//   server how much already arrived and resumes from there (the server binds
//   the id to the session, so another tab or browser starts over).
// - **the slices** (steps 2 and 5): the page polls `…/avancer`; each call
//   runs about five seconds of analysis or import on the server and answers
//   where the run is. When the run changes state, the page reloads and the
//   server draws the next step. Closing the page stops the import, which is
//   what the screen says it does.
//
// Everything written into the page goes through textContent or a number:
// nothing a server or a file name says is ever parsed as markup.
(function () {
    'use strict';

    /**
     * The sessionStorage key a deposit of this file is remembered under.
     *
     * @param {string} importer
     * @param {{name: string, size: number, lastModified: number}} file
     * @returns {string}
     */
    function uploadKey(importer, file) {
        return 'scoutmagic:data-migration:' + importer + ':' + file.size + ':' + file.lastModified + ':' + file.name;
    }

    /**
     * @param {number} sent
     * @param {number} total
     * @returns {number} whole percent, 0..100
     */
    function percentOf(sent, total) {
        if (!(total > 0)) {
            return 0;
        }
        return Math.max(0, Math.min(100, Math.floor((100 * sent) / total)));
    }

    /**
     * Whether a poll answer means the page must be drawn again by the
     * server: the run left the state the page was drawn for.
     *
     * @param {string|null} drawnFor
     * @param {{state?: string}|null} answer
     * @returns {boolean}
     */
    function needsReload(drawnFor, answer) {
        if (!answer || typeof answer.state !== 'string') {
            return false;
        }
        if (drawnFor === 'confirmed' && answer.state === 'running') {
            return false;
        }
        return answer.state !== drawnFor;
    }

    /**
     * Only this module's own endpoints are ever requested or followed,
     * whatever the island says: the check sits here, where the URL is
     * used, rather than trusting the template that wrote it.
     *
     * @param {unknown} url
     * @returns {boolean}
     */
    function isMigrationUrl(url) {
        return typeof url === 'string'
            && /^\/admin\/migration-donnees\/[a-z0-9-]{1,50}(\/(depot|avancer))?$/.test(url);
    }

    /**
     * @param {string} key
     * @returns {any}
     */
    function readSession(key) {
        try {
            var raw = window.sessionStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * @param {string} key
     * @param {any} value
     */
    function writeSession(key, value) {
        try {
            if (value === null) {
                window.sessionStorage.removeItem(key);
            } else {
                window.sessionStorage.setItem(key, JSON.stringify(value));
            }
        } catch (e) {
            // A private window may refuse storage; the deposit still works,
            // it just cannot resume after a reload.
        }
    }

    /**
     * @param {HTMLElement|null} bar the .progress element
     * @param {number} percent
     */
    function setBar(bar, percent) {
        if (!bar) {
            return;
        }
        bar.setAttribute('aria-valuenow', String(percent));
        var fill = /** @type {HTMLElement|null} */ (bar.querySelector('.progress-bar'));
        if (fill) {
            fill.style.width = percent + '%';
        }
    }

    /**
     * @param {any} page
     * @param {File} file
     */
    function deposit(page, file) {
        var box = document.getElementById('data-migration-upload');
        var name = document.getElementById('data-migration-upload-name');
        var percentLabel = document.getElementById('data-migration-upload-percent');
        var bar = document.getElementById('data-migration-upload-bar');
        var error = document.getElementById('data-migration-upload-error');

        if (error) {
            error.hidden = true;
        }
        if (!/\.zip$/i.test(file.name)) {
            showError(error, 'Choisissez un fichier ZIP.');
            return;
        }
        if (page.max_bytes && file.size > page.max_bytes) {
            showError(error, 'Ce fichier dépasse la taille acceptée pour cet import.');
            return;
        }

        if (box) {
            box.hidden = false;
        }
        if (name) {
            name.textContent = file.name;
        }

        var key = uploadKey(page.importer, file);
        var remembered = readSession(key);
        var uploadId = remembered && typeof remembered.uploadId === 'string'
            ? remembered.uploadId
            : window.ScoutMagicChunkedUpload.newUploadId();
        writeSession(key, { uploadId: uploadId });

        /** @param {number} sent */
        function progress(sent) {
            var pct = percentOf(sent, file.size);
            if (percentLabel) {
                percentLabel.textContent = 'envoi ' + pct + ' %';
            }
            setBar(bar, pct);
        }

        probe(page, uploadId).then(function (received) {
            progress(received);
            return window.ScoutMagicChunkedUpload.uploadInChunks(file, page.urls.upload, {
                uploadId: uploadId,
                startOffset: received,
                csrfToken: window.ScoutMagicApi.csrfToken(),
                lastFields: { file_name: file.name },
                onProgress: progress
            });
        }).then(function () {
            writeSession(key, null);
            window.location.reload();
        }, function (/** @type {Error} */ failure) {
            showError(error, failure && failure.message ? failure.message : 'L\'envoi a échoué.');
        });
    }

    /**
     * How many bytes the server already holds under this upload id.
     *
     * @param {any} page
     * @param {string} uploadId
     * @returns {Promise<number>}
     */
    function probe(page, uploadId) {
        var form = new FormData();
        form.append('upload_id', uploadId);
        form.append('probe', '1');
        form.append('_csrf_token', window.ScoutMagicApi.csrfToken());
        return fetch(page.urls.upload, { method: 'POST', body: form }).then(function (response) {
            return response.json();
        }).then(function (data) {
            return data && typeof data.received === 'number' ? data.received : 0;
        }, function () {
            return 0;
        });
    }

    /**
     * @param {HTMLElement|null} element
     * @param {string} message
     */
    function showError(element, message) {
        if (element) {
            element.textContent = message;
            element.hidden = false;
        }
    }

    /**
     * Steps 2 and 5: drive the slices until the run changes state.
     *
     * @param {any} page
     */
    function drive(page) {
        var slot = window.ScoutMagicApi.pollSlot();
        var errorBox = document.getElementById('data-migration-run-error');
        var pause = document.getElementById('data-migration-pause');
        if (pause) {
            pause.addEventListener('submit', function () { slot.stop(); });
        }

        slot.start(window.ScoutMagicApi.poll(function () {
            return window.ScoutMagicApi.postJson(page.urls.advance, {}).then(function (res) {
                if (!res.ok || !res.data || res.data.success !== true) {
                    if (errorBox && res.status !== 0) {
                        errorBox.textContent = res.data && res.data.error ? String(res.data.error) : 'Le serveur n\'a pas répondu. Nouvel essai…';
                        errorBox.hidden = false;
                    }
                    return true;
                }
                if (errorBox) {
                    errorBox.hidden = true;
                }
                render(res.data);
                if (needsReload(page.state, res.data)) {
                    window.location.reload();
                    return false;
                }
                return true;
            });
        }, { intervalMs: 300 }));
    }

    /**
     * @param {any} data a poll answer
     */
    function render(data) {
        var bar = document.getElementById('data-migration-progress');
        if (typeof data.percent === 'number') {
            setBar(bar, data.percent);
        }
        var label = document.getElementById('data-migration-analyse-label');
        if (label) {
            label.textContent = data.phase === 'verify'
                ? 'Vérification du contenu de l\'archive…'
                : 'Analyse des éléments : ' + Number(data.analysed || 0) + ' lus.';
        }
        ['imported', 'present', 'ignored', 'errors'].forEach(function (key) {
            var node = document.getElementById('data-migration-count-' + key);
            if (node) {
                node.textContent = String(Number(data[key] || 0));
            }
        });
        var done = document.getElementById('data-migration-done');
        if (done) {
            done.textContent = String(Number(data.imported || 0) + Number(data.present || 0)
                + Number(data.ignored || 0) + Number(data.errors || 0));
        }
    }

    function init() {
        var page = window.ScoutMagicApi.pageData('data-migration-page');
        if (!page || !page.urls || !isMigrationUrl(page.urls.upload) || !isMigrationUrl(page.urls.advance)) {
            return;
        }

        if (page.step === 'source') {
            var zone = document.getElementById('data-migration-drop-zone');
            var input = /** @type {HTMLInputElement|null} */ (document.getElementById('data-migration-file'));
            if (zone && input && window.ScoutMagicDropZone) {
                window.ScoutMagicDropZone.bind(zone, function (files) {
                    if (files && files.length > 0) {
                        deposit(page, files[0]);
                    }
                }, { input: input });
            }
        } else if (page.step === 'analyse' || page.step === 'run') {
            drive(page);
        }
    }

    window.ScoutMagicDataMigration = {
        uploadKey: uploadKey,
        percentOf: percentOf,
        needsReload: needsReload,
        isMigrationUrl: isMigrationUrl
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
