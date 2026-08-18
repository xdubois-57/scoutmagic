<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Notification\NotificationService;
use Core\Scheduler\SchedulerService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

class AlbumService
{
    private const OG_IMAGE_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const OG_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private AlbumRepository $albumRepository,
        private MediaRepository $mediaRepository,
        private GalleryAccessService $accessService,
        private OgScraperService $ogScraperService,
        private StorageBackendFactory $storageBackendFactory,
        private StorageLocationRepository $storageLocationRepository,
        private StorageLocationService $storageLocationService,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private UploadHandler $uploadHandler,
        private ?NotificationService $notificationService = null,
        private ?UserAccountRepository $userAccountRepository = null
    ) {
    }

    public function findById(int $id): ?Album
    {
        return $this->albumRepository->findById($id);
    }

    /**
     * @return Album[]
     */
    public function findAllForManage(): array
    {
        return $this->albumRepository->findAll();
    }

    /**
     * @param int[] $sectionIds
     * @param int[] $scoutYearIds
     * @return Album[]
     */
    public function findVisibleForMember(array $sectionIds, array $scoutYearIds): array
    {
        return $this->albumRepository->findVisible($sectionIds, $scoutYearIds);
    }

    /**
     * @throws GalleryException on an invalid type, a disabled type, or a
     *                           section the current chief doesn't manage
     */
    public function create(
        string $type,
        string $title,
        ?string $subtitle,
        string $albumDate,
        ?int $sectionId,
        ?string $externalUrl,
        int $createdBy,
        Role $role,
        string $email
    ): Album {
        $this->assertValidType($type);
        $this->assertTypeAllowed($type);
        if (!$this->accessService->canManageAlbum($role, $sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        if ($type === Album::TYPE_EXTERNAL && ($externalUrl === null || trim($externalUrl) === '')) {
            throw new GalleryException('Un lien est obligatoire pour un album externe.');
        }
        // The storage location is never chosen by the caller — a local
        // album always uses the current default location. Changing it
        // afterward is a superadmin-triggered migration (Configuration >
        // Galerie), never a per-creation choice.
        if ($type === Album::TYPE_LOCAL) {
            $this->storageLocationService->ensureLegacyLocationBackfilled();
            $defaultLocation = $this->storageLocationRepository->findDefault();
            if ($defaultLocation === null) {
                throw new GalleryException('Aucun emplacement de stockage par défaut n\'est configuré.');
            }
            $storageLocationId = $defaultLocation->id;
        } else {
            $storageLocationId = null;
        }

        // External albums: the link is what the chief actually has in
        // hand, the title is fetched from the link's own og:title — never
        // block on it upfront the way a local album (no such source to
        // fall back on) still must.
        $ogTags = null;
        if ($type === Album::TYPE_EXTERNAL && $externalUrl !== null) {
            $ogTags = $this->fetchOgTagsBestEffort($externalUrl);
        }

        if (trim($title) === '') {
            $ogTitle = $ogTags['title'] ?? '';
            if ($type === Album::TYPE_EXTERNAL && trim($ogTitle) !== '') {
                $title = trim($ogTitle);
            } else {
                $this->assertValidTitle($title);
            }
        }

        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $id = $this->albumRepository->create($type, $title, $subtitle, $albumDate, $sectionId, $scoutYearId, $externalUrl, $storageLocationId, $createdBy);

        if ($ogTags !== null) {
            $ogImageFileId = $this->cacheOgImage($id, $ogTags['image'], $createdBy);
            $this->albumRepository->updateOgMetadata($id, $ogTags['title'], $ogTags['description'], $ogTags['image'], $ogImageFileId);
        }

        $created = $this->albumRepository->findById($id);
        \assert($created !== null);
        $this->dispatchAlbumPublished($created, $createdBy);

        return $created;
    }

    /**
     * Notification centre + push: "a new album was published" — every
     * identified member is a candidate recipient (no per-section
     * targeting, matching the calendar module's own "every identified
     * member" simplification for its event notifications); dispatch()
     * itself re-checks role_min per recipient and never pushes to the
     * chief who created the album.
     */
    private function dispatchAlbumPublished(Album $album, int $createdBy): void
    {
        if ($this->notificationService === null || $this->userAccountRepository === null) {
            return;
        }

        $recipients = array_map(
            static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
            $this->userAccountRepository->findAllIds()
        );
        if ($recipients === []) {
            return;
        }

        $this->notificationService->dispatch('gallery.album_published', $recipients, [
            'title' => 'Nouvel album',
            'body' => $album->title,
            'url' => '/gallery',
        ], $createdBy);
    }

    /**
     * @throws GalleryException when the current chief doesn't manage the
     *                           album's (old or new) section
     */
    public function update(
        int $id,
        string $title,
        ?string $subtitle,
        string $albumDate,
        ?int $sectionId,
        ?string $externalUrl,
        Role $role,
        string $email
    ): Album {
        $existing = $this->albumRepository->findById($id);
        if ($existing === null || $existing->isDelegated()) {
            // A delegated album is reachable only through its owning
            // module — indistinguishable here from a nonexistent one.
            throw new GalleryException('Album introuvable.');
        }
        $this->assertValidTitle($title);
        if (!$this->accessService->canManageAlbum($role, $existing->sectionId, $email)
            || !$this->accessService->canManageAlbum($role, $sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        if ($existing->type === Album::TYPE_EXTERNAL && ($externalUrl === null || trim($externalUrl) === '')) {
            throw new GalleryException('Un lien est obligatoire pour un album externe.');
        }

        $this->albumRepository->update($id, $title, $subtitle, $albumDate, $sectionId, $externalUrl);

        if ($existing->type === Album::TYPE_EXTERNAL && $externalUrl !== null && $externalUrl !== $existing->externalUrl) {
            $tags = $this->fetchOgTagsBestEffort($externalUrl);
            if ($tags !== null) {
                $ogImageFileId = $this->cacheOgImage($id, $tags['image'], $existing->createdBy);
                $this->albumRepository->updateOgMetadata($id, $tags['title'], $tags['description'], $tags['image'], $ogImageFileId);
            }
        }

        return $this->albumRepository->findById($id);
    }

    /**
     * Deletes the album row (cascades to gallery_media in the DB) and
     * every stored file for it — DB cascade never touches the storage
     * backend, so that cleanup is explicit here (module spec).
     *
     * @throws GalleryException when the current chief doesn't manage this album
     */
    public function delete(int $id, Role $role, string $email): void
    {
        $album = $this->albumRepository->findById($id);
        if ($album === null || $album->isDelegated()) {
            throw new GalleryException('Album introuvable.');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        if ($album->migrationStatus === Album::MIGRATION_IN_PROGRESS) {
            throw new GalleryException('Une migration est en cours pour cet album — réessayez une fois celle-ci terminée.');
        }

        if ($album->isLocal()) {
            $location = $this->storageLocationService->resolveLocationForAlbum($album);
            if ($location !== null) {
                $this->storageBackendFactory->create($location)->deletePrefix((string) $id);
            }
        }

        $this->albumRepository->delete($id);
    }

    /**
     * @throws GalleryException when $mediaId doesn't belong to this album,
     *                           or the current chief doesn't manage it
     */
    public function setCover(int $albumId, int $mediaId, Role $role, string $email): void
    {
        $album = $this->albumRepository->findById($albumId);
        if ($album === null || $album->isDelegated()) {
            throw new GalleryException('Album introuvable.');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }

        $media = $this->mediaRepository->findById($mediaId);
        if ($media === null || $media->albumId !== $albumId) {
            throw new GalleryException('Ce média n\'appartient pas à cet album.');
        }

        $this->albumRepository->setCoverMediaId($albumId, $mediaId);
    }

    /**
     * @throws GalleryException when the album isn't external, or the fetch fails
     */
    public function refreshOg(int $albumId, Role $role, string $email): Album
    {
        $album = $this->albumRepository->findById($albumId);
        if ($album === null || $album->isDelegated()) {
            throw new GalleryException('Album introuvable.');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        if ($album->isLocal() || $album->externalUrl === null) {
            throw new GalleryException('Cet album n\'est pas un album externe.');
        }

        $tags = $this->ogScraperService->fetch($album->externalUrl);
        $ogImageFileId = $this->cacheOgImage($albumId, $tags['image'], $album->createdBy);
        $this->albumRepository->updateOgMetadata($albumId, $tags['title'], $tags['description'], $tags['image'], $ogImageFileId);

        return $this->albumRepository->findById($albumId);
    }

    /**
     * Starts (or retries, after a 'failed' attempt) a background storage
     * migration to a different location — the actual file copy runs out of
     * band via Task\MigrateAlbumStorageHandler, scheduled to run right
     * away (module spec: "in background").
     *
     * @throws GalleryException on an invalid album/target, a migration
     *                           already in progress, or an unhealthy target
     */
    public function startMigration(int $albumId, int $targetLocationId, Role $role, string $email): void
    {
        $album = $this->albumRepository->findById($albumId);
        if ($album === null || $album->isDelegated()) {
            throw new GalleryException('Album introuvable.');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        if (!$album->isLocal()) {
            throw new GalleryException('Seuls les albums locaux peuvent être migrés.');
        }
        if ($album->migrationStatus === Album::MIGRATION_IN_PROGRESS) {
            throw new GalleryException('Une migration est déjà en cours pour cet album.');
        }
        if ($targetLocationId === $album->storageLocationId) {
            throw new GalleryException('L\'emplacement cible doit être différent de l\'emplacement actuel.');
        }

        $target = $this->storageLocationRepository->findById($targetLocationId);
        if ($target === null) {
            throw new GalleryException('Emplacement cible introuvable.');
        }
        $target = $this->storageLocationService->checkFresh($target);
        if ($target->lastCheckOk !== true) {
            throw new GalleryException('Cet emplacement n\'est pas disponible actuellement — testez-le avant de migrer.');
        }

        $this->albumRepository->startMigration($albumId, $target->id);
        $this->schedulerService->scheduleAfter('gallery', 'migrate_album_storage', 0, ['album_id' => $albumId], 'album_migration_' . $albumId);
    }

    /**
     * @return array{title: string, description: string, image: string}|null null on any fetch failure
     */
    private function fetchOgTagsBestEffort(string $url): ?array
    {
        try {
            return $this->ogScraperService->fetch($url);
        } catch (GalleryException) {
            // Best-effort — module spec: a failed fetch never blocks
            // saving the album, the "Rafraîchir" button lets the chief retry.
            return null;
        }
    }

    /**
     * Best-effort local cache of an og:image (Service\OgScraperService::
     * fetchImageBytes()) — never blocks album creation/refresh, exactly
     * like fetchOgTagsBestEffort() above. The image is downloaded once,
     * EXIF-stripped like any other upload (Core\File\UploadHandler), and
     * served the normal way afterward (`/files/{id}`) rather than
     * hotlinked directly from the external site: the CSP img-src is
     * deliberately narrow and can't whitelist an arbitrary third-party
     * domain per album, and hotlinking would leak the viewer's IP/user
     * agent to that third party without consent.
     */
    private function cacheOgImage(int $albumId, ?string $imageUrl, int $createdBy): ?int
    {
        if ($imageUrl === null || trim($imageUrl) === '') {
            return null;
        }

        $bytes = $this->ogScraperService->fetchImageBytes($imageUrl);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_og_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            file_put_contents($tmpPath, $bytes);
            return $this->uploadHandler->handle(
                ['tmp_name' => $tmpPath, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, 'name' => 'og_image'],
                "gallery/{$albumId}/og", self::OG_IMAGE_ALLOWED_MIMES, self::OG_IMAGE_MAX_BYTES, 'identified', 'gallery', $createdBy
            );
        } catch (UploadException) {
            // Best-effort — an unsupported/oversized image just leaves the
            // album without a cached preview, exactly like a failed
            // metadata scrape leaves the title/description null.
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }

    private function assertValidType(string $type): void
    {
        if (!in_array($type, Album::TYPES, true)) {
            throw new GalleryException('Type d\'album invalide.');
        }
    }

    private function assertValidTitle(string $title): void
    {
        if (trim($title) === '') {
            throw new GalleryException('Le titre est obligatoire.');
        }
    }

    /**
     * Whether a hosted (local) album is allowed to be created is derived
     * from "does at least one storage location currently exist" rather
     * than a separate setting an admin has to remember to keep in sync —
     * the backfill already guarantees a location always exists on a fresh/
     * upgraded install, and the "can't delete a referenced location" guard
     * already prevents deleting the way into a state where existing hosted
     * albums have no location.
     */
    private function assertTypeAllowed(string $type): void
    {
        if ($type === Album::TYPE_LOCAL) {
            $this->storageLocationService->ensureLegacyLocationBackfilled();
            if ($this->storageLocationRepository->findAll() === []) {
                throw new GalleryException('Aucun emplacement de stockage configuré — impossible de créer un album local.');
            }
            return;
        }

        if (!(bool) $this->settingService->get('gallery_allow_external', 'gallery', true)) {
            throw new GalleryException('Ce type d\'album est désactivé.');
        }
    }
}
