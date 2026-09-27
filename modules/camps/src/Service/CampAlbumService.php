<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Journal\JournalService;
use Modules\Camps\Repository\Camp;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Api\GalleryException;

/**
 * Photos of a stay, hosted by the gallery module as a delegated album
 * (docs/module-development.md, ARCHITECTURE.md §7.5).
 *
 * The album belongs to the CAMP, not to the place: photos are of one
 * summer, and a place's sheet aggregates its stays' albums rather than
 * pooling ten years of pictures into one undated heap. It also means a
 * place merge moves nothing — the stays change place, and their albums
 * follow them untouched.
 *
 * OPTIONAL dependency, nullable: without the gallery module every method
 * here is a no-op and the camp page simply has no photos section. A
 * module whose main job is not photos must not become unusable because
 * the gallery is disabled.
 *
 * **Absorbing a refusal is right; hiding it is not** (issue #637). The two
 * reads a page is built on — {@see albumIdFor()} and {@see listMedia()} —
 * survive a GalleryException, and each one says so in the journal: until
 * #637 both catches were silent, and the photos page turned the absorbed
 * null into « le module Galerie est désactivé », a cause that was not the
 * cause, with the real one thrown away.
 */
class CampAlbumService
{
    /** `event_log.description` is a VARCHAR(500) (schema/core.sql). */
    private const JOURNAL_DESCRIPTION_MAX_LENGTH = 500;

    public function __construct(
        private AuditService $audit,
        private ?DelegatedAlbumManager $albums = null,
        private ?JournalService $journal = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->albums !== null;
    }

    /**
     * The stay's album id, created on first use. Null when the gallery is
     * absent, or when the configured storage cannot host a delegated
     * album at all (a public-prefix S3 location) — that refusal is
     * gallery's, it is legitimate, and it must not take the whole camp
     * page down with it.
     */
    public function albumIdFor(Camp $camp, string $title, int $createdBy): ?int
    {
        if ($this->albums === null) {
            return null;
        }

        try {
            return $this->albums->ensureAlbum(
                CampAlbumAccessChecker::OWNER_TYPE,
                $camp->id,
                $title,
                $camp->endDate ?? ($camp->yearOnly !== null ? $camp->yearOnly . '-07-01' : date('Y-m-d')),
                $createdBy
            )->id;
        } catch (GalleryException $e) {
            $this->journalRefusal('camp_album_unavailable', "l'album du séjour n'a pas pu être obtenu", $camp->id, $e);

            return null;
        }
    }

    /**
     * The stay's album id ONLY if it already exists — never creating one.
     *
     * Distinct from albumIdFor() on purpose: that one is
     * create-if-missing, which is right on the photos page and wrong
     * anywhere else. A merge asking "does the losing stay have photos"
     * must not answer by creating an empty album for it.
     */
    public function existingAlbumIdFor(Camp $camp): ?int
    {
        if ($this->albums === null) {
            return null;
        }

        try {
            $album = $this->albums->findAlbum(CampAlbumAccessChecker::OWNER_TYPE, $camp->id);
        } catch (GalleryException) {
            return null;
        }

        return $album?->id;
    }

    /**
     * The album's photos, or **null when they could not be read** — which is
     * not the same answer as an album with no photo in it, and a page must be
     * able to tell the two apart (issue #637). Without a gallery, or without
     * an album, there is nothing to read: that is an empty list.
     *
     * $campId only names the stay in the journal entry a failed read
     * leaves: an album id alone is gallery-internal, and appears nowhere a
     * chief could look it up.
     *
     * @return DelegatedMedia[]|null
     */
    public function listMedia(?int $albumId, ?int $campId = null): ?array
    {
        if ($this->albums === null || $albumId === null) {
            return [];
        }

        try {
            return $this->albums->listMedia($albumId);
        } catch (GalleryException $e) {
            $this->journalRefusal(
                'camp_album_unreadable',
                "les photos de l'album n'ont pas pu être lues",
                $campId,
                $e,
                $albumId
            );

            return null;
        }
    }

    /**
     * @param array<string, mixed> $uploadedFile a $_FILES entry
     */
    public function addPhoto(Camp $camp, int $albumId, array $uploadedFile, ?int $accountId): void
    {
        if ($this->albums === null) {
            throw new CampsException('Les photos ne sont pas disponibles : le module Galerie est désactivé.');
        }

        try {
            $this->albums->addMedia($albumId, $uploadedFile, $accountId);
        } catch (GalleryException $e) {
            throw new CampsException($e->getMessage(), 0, $e);
        }

        $this->audit->record(
            CampService::ENTITY_TYPE,
            $camp->id,
            'photos',
            null,
            null,
            AuditSource::Human,
            'Photo ajoutée',
            null,
            $accountId
        );
    }

    public function deletePhoto(Camp $camp, int $albumId, int $mediaId, ?int $accountId): void
    {
        if ($this->albums === null) {
            return;
        }

        try {
            $this->albums->deleteMedia($albumId, $mediaId);
        } catch (GalleryException $e) {
            throw new CampsException($e->getMessage(), 0, $e);
        }

        $this->audit->record(
            CampService::ENTITY_TYPE,
            $camp->id,
            'photos',
            null,
            null,
            AuditSource::Human,
            'Photo supprimée',
            null,
            $accountId
        );
    }

    /**
     * Merges one stay's photos into another's — used when two stays are
     * merged. Returns how many moved, or 0 when the gallery is absent.
     */
    public function movePhotos(int $fromAlbumId, int $toAlbumId): int
    {
        if ($this->albums === null) {
            return 0;
        }

        try {
            return $this->albums->moveMedia(CampAlbumAccessChecker::OWNER_TYPE, $fromAlbumId, $toAlbumId);
        } catch (GalleryException) {
            return 0;
        }
    }

    /**
     * The trace a survived refusal leaves. The gallery's own sentence is kept
     * (a GalleryException is written for a reader, and names no one); the
     * identifiers are numeric only, as the journal requires.
     *
     * The gallery's sentence can quote an administrator's text — a storage
     * location's label — so it is shortened to fit the journal's
     * VARCHAR(500): a longer description is refused by MySQL, and the
     * exception would escape the very catch that called this.
     */
    private function journalRefusal(
        string $event,
        string $what,
        ?int $campId,
        GalleryException $e,
        ?int $albumId = null
    ): void {
        $prefix = sprintf('Photos d\'un séjour : %s (', $what);
        $suffix = ').';
        $budget = max(1, self::JOURNAL_DESCRIPTION_MAX_LENGTH - mb_strlen($prefix) - mb_strlen($suffix));
        $message = trim((string) preg_replace('/\s+/u', ' ', $e->getMessage()));
        if (mb_strlen($message) > $budget) {
            $message = mb_substr($message, 0, $budget - 1) . '…';
        }

        $this->journal?->log(
            'camps',
            $event,
            'warning',
            $prefix . $message . $suffix,
            array_filter(['camp_id' => $campId, 'album_id' => $albumId], static fn(?int $id): bool => $id !== null)
        );
    }
}
