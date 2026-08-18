<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * Public contract for a module that wants gallery to host and store an
 * album it owns and access-controls itself — a delegated album
 * (ARCHITECTURE.md §7.5, same shape as Api\GalleryAlbumProvider). Storage
 * stays fully configurable (local or S3, whichever the admin has
 * configured) — that is the whole point of going through gallery instead
 * of the owning module rolling its own file handling.
 *
 * Authorisation is entirely the caller's responsibility. Every method
 * here takes no Role and no email and performs none of gallery's own
 * section-based checks — unlike Service\MediaService::upload(), which
 * gates every write behind Service\GalleryAccessService::canManageAlbum().
 * By the time an owning module calls any of these, it has already decided
 * the caller may act; gallery only stores and serves. The one thing
 * gallery still enforces on its own is *read* access to the served
 * media — see Api\DelegatedAlbumAccessChecker, which the owning module
 * registers separately to answer that question when a viewer requests
 * GET /gallery/media/{id}/{size}.
 */
interface DelegatedAlbumManager
{
    /**
     * Finds the delegated album already owned by (ownerType, ownerId), or
     * creates one when none exists yet — idempotent, safe to call on
     * every request that might need the album. $storageLocationId pins the
     * album to a specific configured location; null uses whichever
     * location is currently the default, same resolution as an ordinary
     * local album.
     *
     * @throws \Modules\Gallery\Service\GalleryException when the resolved
     *         storage location has a public URL configured — a delegated
     *         album must live on a location gallery can serve only
     *         through a genuinely access-controlled path (a private
     *         bucket, or presigned URLs), never a public-prefix one where
     *         presigning would change nothing.
     */
    public function ensureAlbum(
        string $ownerType,
        int $ownerId,
        string $title,
        string $albumDate,
        int $createdBy,
        ?int $storageLocationId = null
    ): DelegatedAlbum;

    /**
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @throws \Modules\Gallery\Service\GalleryException on an invalid
     *         file, a disabled type, over-quota, or an unknown/
     *         non-delegated $albumId
     */
    public function addMedia(int $albumId, array $uploadedFile, ?int $accountId): DelegatedMedia;

    /**
     * @return DelegatedMedia[] ordered the same way the album's own grid would be
     */
    public function listMedia(int $albumId): array;

    /**
     * @throws \Modules\Gallery\Service\GalleryException when $mediaId
     *         doesn't belong to $albumId
     */
    public function deleteMedia(int $albumId, int $mediaId): void;

    /**
     * Deletes the album row, every one of its media rows (DB cascade) and
     * every stored object for them (thumb/medium/large/original, on
     * whichever storage backend the album lives on) — the DB cascade never
     * touches the storage backend on its own, same as an ordinary album's
     * own Service\AlbumService::delete().
     */
    public function deleteAlbum(int $albumId): void;

    /**
     * Whether a video may currently be added to a delegated album —
     * addMedia() already refuses one server-side when this is false
     * (Service\MediaService::upload()'s own rule: the gallery_allow_video
     * setting AND ffmpeg/ffprobe both present), so this exists purely so
     * an owning module's own composer can hide the option proactively
     * instead of only ever finding out from a rejected upload.
     */
    public function videoUploadAllowed(): bool;
}
