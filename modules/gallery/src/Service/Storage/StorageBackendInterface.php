<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service\Storage;

/**
 * Where the derived photo/video sizes (thumb/medium/large/original) are
 * written — either the local disk (LocalStorageBackend) or an S3-compatible
 * object store (S3StorageBackend), selected via the gallery_storage_backend
 * setting (Service\StorageBackendFactory). $key is always a relative path
 * such as "{albumId}/med_{mediaId}.jpg" — never an absolute path or URL.
 */
interface StorageBackendInterface
{
    public function put(string $key, string $contents, string $mimeType): void;

    public function get(string $key): string;

    public function delete(string $key): void;

    /**
     * Deletes every object whose key starts with $prefix — used for whole
     * album cleanup ("{albumId}/").
     */
    public function deletePrefix(string $prefix): void;

    /**
     * A URL to serve $key to a browser — for LocalStorageBackend this is
     * never called directly by a template (files are streamed through
     * Controller\GalleryController::serveMedia() instead); for
     * S3StorageBackend it's either the configured public URL prefix or a
     * time-limited pre-signed URL.
     *
     * $ttl is a pre-signing expiry understood by Aws\S3\S3Client::
     * createPresignedRequest() (e.g. '+1 hour', '+5 minutes') — only ever
     * meaningful for S3StorageBackend without a public URL configured;
     * LocalStorageBackend ignores it entirely (its "URL" is this app's own
     * access-controlled route, not a time-limited grant). Controller\
     * GalleryController::serveMedia() passes a short TTL for a delegated
     * album's media, minted fresh per request and never stored or logged;
     * every other caller leaves it at the default.
     */
    public function url(string $key, string $ttl = '+1 hour'): string;

    public function exists(string $key): bool;
}
