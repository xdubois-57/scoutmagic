/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Shared chunked-upload helper (audit M2). `.user.ini` limits are
// per-directory and every route runs through public/index.php, so a
// post_max_size big enough for a 2 GB gallery video applied to /login and
// every other endpoint too. Instead of a huge global ceiling, large files
// are sliced into ~8 MB chunks sent sequentially through the consumer's
// normal, RBAC/CSRF-guarded route; the server reassembles them
// (Core\File\ChunkedUploadStore) and runs the finished file through the
// same validation as a single-POST upload.
//
// Exposed as window.ScoutMagicChunkedUpload = { uploadInChunks, newUploadId,
// CHUNK_SIZE, CHUNK_THRESHOLD }. Files at or below CHUNK_THRESHOLD should
// keep using the plain single-POST path — the global post_max_size (48M)
// covers them.
(function () {
    'use strict';

    var CHUNK_SIZE = 8 * 1024 * 1024;
    var CHUNK_THRESHOLD = 24 * 1024 * 1024;

    // 32 lowercase hex chars — the server's expected id shape. The id is
    // only ever half of the on-disk name (the server hashes it with the
    // session id), so it needs to be unique, not secret; crypto randomness
    // makes collisions a non-issue.
    function newUploadId() {
        var bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    /**
     * Sends `file` to `url` as sequential chunks. Resolves with
     * { uploadId, data } where `data` is the final chunk's JSON response;
     * rejects with an Error whose message is the server's error string.
     *
     * options: {
     *   csrfToken:  appended as _csrf_token on every chunk,
     *   fields:     extra FormData fields on every chunk,
     *   lastFields: extra FormData fields on the last chunk only,
     *   onProgress: function (sentBytes, totalBytes),
     *   chunkSize, uploadId: overrides (mostly for tests)
     * }
     */
    function uploadInChunks(file, url, options) {
        var opts = options || {};
        var chunkSize = opts.chunkSize || CHUNK_SIZE;
        var uploadId = opts.uploadId || newUploadId();
        var total = file.size;
        // A 409 means the server's assembled size disagrees with our offset
        // (a retried chunk after a lost response, typically); it reports the
        // real size so we resume from there. Bounded so a persistent
        // disagreement can't loop forever.
        var resyncs = 0;

        function sendFrom(offset) {
            var end = Math.min(offset + chunkSize, total);
            var isLast = end >= total;
            var formData = new FormData();
            formData.append('file', file.slice(offset, end), 'chunk');
            formData.append('upload_id', uploadId);
            formData.append('chunk_offset', String(offset));
            formData.append('last', isLast ? '1' : '0');
            if (opts.csrfToken) formData.append('_csrf_token', opts.csrfToken);
            var extra = { ...(opts.fields || {}), ...(isLast ? (opts.lastFields || {}) : {}) };
            Object.keys(extra).forEach(function (key) { formData.append(key, extra[key]); });

            return fetch(url, { method: 'POST', body: formData }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (response.status === 409 && typeof data.received === 'number'
                        && data.received < total && resyncs < 3) {
                        resyncs++;
                        return sendFrom(data.received);
                    }
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.error || 'Erreur réseau.');
                    }
                    if (opts.onProgress) opts.onProgress(end, total);
                    return isLast ? { uploadId: uploadId, data: data } : sendFrom(end);
                });
            });
        }

        return sendFrom(0);
    }

    window.ScoutMagicChunkedUpload = {
        uploadInChunks: uploadInChunks,
        newUploadId: newUploadId,
        CHUNK_SIZE: CHUNK_SIZE,
        CHUNK_THRESHOLD: CHUNK_THRESHOLD
    };
})();
