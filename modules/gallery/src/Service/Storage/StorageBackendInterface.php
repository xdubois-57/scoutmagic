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
     * Absolute filesystem path of $key when the backend stores it as a local
     * file, or null when it does not (e.g. an S3 object that would have to be
     * downloaded first). Lets a caller stream a large local object straight
     * off disk instead of buffering it whole in memory (audit M10).
     */
    public function localPath(string $key): ?string;

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

    /**
     * Copies $fromKey to $toKey WITHIN this backend, without the bytes ever
     * travelling through PHP — a server-side S3 CopyObject, a filesystem
     * copy locally. Exists so a media can change album (Service\
     * DelegatedAlbumService::moveMedia()) and have its renditions follow it
     * under the target album's own "{albumId}/" prefix: leaving them under
     * the source album's prefix would hand deletePrefix() the power to
     * destroy, on the source album's deletion, objects a different album's
     * rows still point at.
     *
     * Same backend only — it cannot move an object from a local location to
     * an S3 one, or between two buckets. A caller spanning two locations
     * has to read and re-write the bytes itself, which is why moveMedia()
     * refuses that case outright rather than pretending this covers it.
     *
     * @throws \RuntimeException when $fromKey can't be read
     */
    public function copy(string $fromKey, string $toKey): void;

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

    /**
     * A URL that stays IDENTICAL across renders within a time window, so
     * the browser can actually cache what it points at — what album pages
     * embed for every thumbnail (Service\MediaService::resolveUrl()).
     * url()'s presigned form embeds its signing time and therefore
     * changes on every call, which made each page view re-download every
     * image. Never for a delegated album's short-lived grants — those
     * stay on url() with an explicit ttl, minted fresh on purpose.
     */
    public function stableUrl(string $key): string;

    public function exists(string $key): bool;
}
