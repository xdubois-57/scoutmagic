<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * Creating an external album **without any side effect**, for a module
 * that brings albums in from another site — the data migration's
 * « Galeries photo » import (ARCHITECTURE.md §7.5).
 *
 * What differs from a chief creating the album on the gallery's own page
 * (`Service\AlbumService::create()`) is only what an import must not do:
 * - **no « Nouvel album » notification** — two hundred historical albums
 *   arriving at once are not news, and the old site announced them long
 *   ago;
 * - no fetch of its own: the caller has already read the page through
 *   `Core\Http\LinkPreviewFetcher` (SSRF-checked, SECURITY.md §17) and
 *   passes what it found;
 * - no scout year guessed from today: the caller names the year, which it
 *   resolved from the album's own date or from the old site, and which
 *   exists.
 *
 * What stays is the gallery's own rules: an external album must be
 * allowed by the gallery's configuration, its address must be an http(s)
 * one, its fields fit their columns, and the page's image is stored the
 * way every other one is (`Core\File\UploadHandler`: real type checked,
 * EXIF stripped, disk budget, served to identified members only).
 *
 * The album then is an ordinary one: it appears in the gallery's lists
 * and filters, and a chief edits or deletes it like any other — no badge,
 * no lock.
 *
 * Authorisation is the caller's: no Role is taken, no section check made.
 */
interface ExternalAlbumWriter
{
    /** Whether the gallery's configuration currently allows external albums at all. */
    public function externalAlbumsAllowed(): bool;

    /**
     * The id of an external album already on this site at the same address,
     * compared after {@see ExternalAlbumUrl::normalize()} — or null.
     */
    public function findExternalAlbumByUrl(string $url): ?int;

    /**
     * Creates the album and returns its id. Writes on the caller's
     * connection and opens no transaction of its own, so a caller's
     * rollback takes the album and its rows with it (only the bytes of
     * the page's image, already written to storage, would stay behind as
     * an unreferenced file).
     *
     * @throws GalleryException when external albums are disabled, or a
     *         field breaks the gallery's rules (address, length, date)
     */
    public function createImportedExternalAlbum(ImportedExternalAlbum $album): int;
}
