<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Modules\Camps\Repository\Camp;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Service\GalleryException;

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
 */
class CampAlbumService
{
    public function __construct(
        private AuditService $audit,
        private ?DelegatedAlbumManager $albums = null
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
        } catch (GalleryException) {
            return null;
        }
    }

    /**
     * @return DelegatedMedia[]
     */
    public function listMedia(?int $albumId): array
    {
        if ($this->albums === null || $albumId === null) {
            return [];
        }

        try {
            return $this->albums->listMedia($albumId);
        } catch (GalleryException) {
            return [];
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
            CampService::ENTITY_TYPE, $camp->id, 'photos', null, null,
            AuditSource::Human, 'Photo ajoutée', null, $accountId
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
            CampService::ENTITY_TYPE, $camp->id, 'photos', null, null,
            AuditSource::Human, 'Photo supprimée', null, $accountId
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
}
