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
use Core\Service\DateInput;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

class AlbumService
{
    private const OG_IMAGE_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const OG_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Mirror gallery_albums' own column widths (schema.sql): the forms cap
     * these client-side, but a direct POST didn't, and an over-long value
     * reached MySQL in strict mode as a PDOException — a 500 where the user
     * should simply be told the field is too long.
     */
    private const MAX_TITLE_LENGTH = 255;
    private const MAX_SUBTITLE_LENGTH = 255;
    private const MAX_EXTERNAL_URL_LENGTH = 500;
    private const MAX_OG_TITLE_LENGTH = 255;
    private const MAX_OG_IMAGE_URL_LENGTH = 500;

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
        private ?UserAccountRepository $userAccountRepository = null,
        private ?StoredFileCleaner $storedFileCleaner = null
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
     * Every album another module owns — for the storage administration
     * page, and nothing else. See Repository\AlbumRepository::findDelegated().
     *
     * @return Album[]
     */
    public function findDelegatedForAdministration(): array
    {
        return $this->albumRepository->findDelegated();
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
        $this->assertValidDate($albumDate);
        if (!$this->accessService->canManageAlbum($role, $sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        if ($type === Album::TYPE_EXTERNAL) {
            $externalUrl = $this->normalizeExternalUrl($externalUrl);
        } else {
            // A local album has no link of its own; never let one be smuggled
            // in on the side.
            $externalUrl = null;
        }
        $this->assertValidLength($subtitle, self::MAX_SUBTITLE_LENGTH, 'Le sous-titre');
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
                // The scraped title is third-party text of unbounded length —
                // truncate rather than reject, the chief never typed it.
                $title = (string) $this->clamp(trim($ogTitle), self::MAX_TITLE_LENGTH);
            } else {
                $this->assertValidTitle($title);
            }
        }
        $this->assertValidLength($title, self::MAX_TITLE_LENGTH, 'Le titre');

        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $id = $this->albumRepository->create($type, $title, $subtitle, $albumDate, $sectionId, $scoutYearId, $externalUrl, $storageLocationId, $createdBy);

        if ($ogTags !== null) {
            $ogImageFileId = $this->cacheOgImage($id, $ogTags['image'], $createdBy);
            $this->persistOgMetadata($id, $ogTags, $ogImageFileId);
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
        $this->assertValidLength($title, self::MAX_TITLE_LENGTH, 'Le titre');
        $this->assertValidLength($subtitle, self::MAX_SUBTITLE_LENGTH, 'Le sous-titre');
        $this->assertValidDate($albumDate);
        if (!$this->accessService->canManageAlbum($role, $existing->sectionId, $email)
            || !$this->accessService->canManageAlbum($role, $sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        $externalUrl = $existing->type === Album::TYPE_EXTERNAL
            ? $this->normalizeExternalUrl($externalUrl)
            : null;

        $this->albumRepository->update($id, $title, $subtitle, $albumDate, $sectionId, $externalUrl);

        if ($existing->type === Album::TYPE_EXTERNAL && $externalUrl !== null && $externalUrl !== $existing->externalUrl) {
            $tags = $this->fetchOgTagsBestEffort($externalUrl);
            if ($tags !== null) {
                $ogImageFileId = $this->cacheOgImage($id, $tags['image'], $existing->createdBy);
                $this->persistOgMetadata($id, $tags, $ogImageFileId);
                $this->discardSupersededOgImage($existing->ogImageFileId, $ogImageFileId);
            }
        }

        $updated = $this->albumRepository->findById($id);
        \assert($updated !== null);

        return $updated;
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

        // Read the media rows before anything is removed — their file_ids are
        // the only handle on the staging originals still on disk.
        $media = $this->mediaRepository->findByAlbumId($id);

        if ($album->isLocal()) {
            $location = $this->storageLocationService->resolveLocationForAlbum($album);
            if ($location !== null) {
                $this->storageBackendFactory->create($location)->deletePrefix((string) $id);
            }
        }

        // Explicit rather than relying on gallery_media's ON DELETE CASCADE:
        // files(id) is referenced by gallery_media.file_id with no ON DELETE
        // clause, so every child row has to be gone before the originals can
        // be reclaimed below — and doing it here makes the outcome identical
        // whether or not the engine actually enforces the cascade.
        foreach ($media as $row) {
            $this->mediaRepository->delete($row->id);
        }

        $this->albumRepository->delete($id);

        foreach ($media as $row) {
            $this->storedFileCleaner?->delete($row->fileId);
        }
        $this->storedFileCleaner?->delete($album->ogImageFileId);
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
        if ($album->isMigrating()) {
            throw new GalleryException('Une migration de stockage est en cours pour cet album — réessayez une fois celle-ci terminée.');
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
        $this->persistOgMetadata($albumId, $tags, $ogImageFileId);
        $this->discardSupersededOgImage($album->ogImageFileId, $ogImageFileId);

        $refreshed = $this->albumRepository->findById($albumId);
        \assert($refreshed !== null);

        return $refreshed;
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
        if ($album === null) {
            throw new GalleryException('Album introuvable.');
        }

        if ($album->isDelegated()) {
            // A delegated album belongs to another module: gallery neither
            // browses it nor edits it, and section management says nothing
            // about who may touch it. Moving it between storage locations is
            // the one thing gallery still owns, and it is an administrator's
            // job — the route is superadmin-only, and this repeats the rule
            // where it is testable rather than trusting the router alone.
            if ($role->level() < Role::ADMIN->level()) {
                throw new GalleryException(
                    'Seul un administrateur peut déplacer un album appartenant à un autre module.'
                );
            }
        } elseif (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
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
        // The invariant Service\DelegatedAlbumService::ensureAlbum() enforces
        // at creation, enforced again at every move: a delegated album must
        // live where gallery can serve it through a genuinely
        // access-controlled path. Migrating one onto a public-prefix
        // location would publish somebody's group photos to anyone holding
        // the URL, silently and for ever.
        if ($album->isDelegated() && $target->s3PublicUrl !== null && $target->s3PublicUrl !== '') {
            throw new GalleryException(
                'Cet album appartient à un autre module et ne peut pas être déplacé vers un '
                . 'emplacement à URL publique : ses médias doivent rester servis par ScoutMagic.'
            );
        }

        $target = $this->storageLocationService->checkFresh($target);
        if ($target->lastCheckOk !== true) {
            throw new GalleryException('Cet emplacement n\'est pas disponible actuellement — testez-le avant de migrer.');
        }

        $this->albumRepository->startMigration($albumId, $target->id);
        $this->schedulerService->scheduleAfter('gallery', 'migrate_album_storage', 0, ['album_id' => $albumId], 'album_migration_' . $albumId);
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string}|null null on any fetch failure
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

    /**
     * Caches the scraped OG metadata, clipped to the widths of the columns
     * holding it (og_title VARCHAR(255), og_image_url VARCHAR(500);
     * og_description is TEXT and needs no cap). Third-party pages can put
     * anything in those tags, and truncating is right here where rejecting
     * would not be: nobody on this side typed these values, and a clipped
     * preview beats a PDOException on album save.
     *
     * Clipping happens at persistence, never before cacheOgImage() — that one
     * has to fetch the FULL og:image URL, and a truncated one fetches nothing.
     *
     * @param array{title: ?string, description: ?string, image: ?string} $tags
     */
    private function persistOgMetadata(int $albumId, array $tags, ?int $ogImageFileId): void
    {
        $this->albumRepository->updateOgMetadata(
            $albumId,
            $this->clamp($tags['title'], self::MAX_OG_TITLE_LENGTH),
            $tags['description'],
            $this->clamp($tags['image'], self::MAX_OG_IMAGE_URL_LENGTH),
            $ogImageFileId
        );
    }

    private function clamp(?string $value, int $max): ?string
    {
        return $value !== null ? mb_substr($value, 0, $max) : null;
    }

    /**
     * Frees the `files` row a previous og:image lived in once a newer one has
     * replaced it on the album — otherwise every "Rafraîchir l'aperçu" click
     * and every URL change left another orphaned image behind.
     */
    private function discardSupersededOgImage(?int $previousFileId, ?int $currentFileId): void
    {
        if ($previousFileId === null || $previousFileId === $currentFileId) {
            return;
        }

        $this->storedFileCleaner?->delete($previousFileId);
    }

    /**
     * An external album's link is rendered as an ordinary href on the member
     * gallery (views/list.html.twig) and the member page, so the scheme is an
     * allowlist, not a formality: FILTER_VALIDATE_URL happily accepts
     * "javascript:alert(1)", which would be stored XSS against every
     * identified member from a chief-level account. http(s) only — the same
     * set Service\OgScraperService will actually fetch.
     *
     * @throws GalleryException on a missing, over-long or non-http(s) URL
     */
    private function normalizeExternalUrl(?string $externalUrl): string
    {
        $externalUrl = trim((string) $externalUrl);
        if ($externalUrl === '') {
            throw new GalleryException('Un lien est obligatoire pour un album externe.');
        }
        $this->assertValidLength($externalUrl, self::MAX_EXTERNAL_URL_LENGTH, 'Le lien');

        $scheme = parse_url($externalUrl, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new GalleryException('Le lien doit commencer par http:// ou https://.');
        }
        if (parse_url($externalUrl, PHP_URL_HOST) === null) {
            throw new GalleryException('Ce lien n\'est pas une adresse web valide.');
        }

        return $externalUrl;
    }

    /**
     * @throws GalleryException when $value is longer than the column holding it
     */
    private function assertValidLength(?string $value, int $max, string $label): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            throw new GalleryException("{$label} ne peut pas dépasser {$max} caractères.");
        }
    }

    private function assertValidType(string $type): void
    {
        if (!in_array($type, Album::TYPES, true)) {
            throw new GalleryException('Type d\'album invalide.');
        }
    }

    /**
     * `album_date` is a DATE column, and what the form posts went into it
     * unread. A dynamic scan put a path-traversal payload there, which
     * MySQL refused as an uncaught PDOException and a 500 — the value was
     * never dangerous, the absence of a check was (SECURITY.md § 35).
     *
     * @throws GalleryException when $value is not a calendar date
     */
    private function assertValidDate(string $value): void
    {
        if (!DateInput::isIso(trim($value))) {
            throw new GalleryException('La date de l\'album n\'est pas une date valide.');
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
