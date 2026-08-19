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
 * object store (S3StorageBackend), selected via the album's own
 * gallery_storage_locations row (Service\Storage\StorageBackendFactory).
 * $key is always a relative path such as "{albumId}/med_{mediaId}.jpg" —
 * never an absolute path or URL.
 */
interface StorageBackendInterface
{
    public function put(string $key, string $contents, string $mimeType): void;

    public function get(string $key): string;

    /**
     * Byte count of $key, or null when it can't be determined (missing
     * object, backend error) — lets a caller serve an HTTP range or refuse
     * an over-large operation without ever reading the whole object into
     * memory first.
     */
    public function size(string $key): ?int;

    /**
     * Reads exactly $length bytes of $key starting at $offset — the whole
     * point being to never materialize a full 1080p video in memory just to
     * answer a browser's `Range:` request (Controller\GalleryController::
     * serveMedia()). May return fewer bytes than asked when the range runs
     * past the end of the object.
     *
     * @throws \RuntimeException when $key can't be read at all
     */
    public function getRange(string $key, int $offset, int $length): string;

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
     */
    public function url(string $key): string;

    public function exists(string $key): bool;
}
