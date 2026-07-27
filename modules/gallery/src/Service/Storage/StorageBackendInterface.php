<?php

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
     */
    public function url(string $key): string;

    public function exists(string $key): bool;
}
