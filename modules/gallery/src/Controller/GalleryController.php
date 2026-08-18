<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\DelegatedAlbumAccessRegistry;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use Twig\Environment;

class GalleryController extends AbstractController
{
    /**
     * A delegated album's presigned S3 URL is a bearer credential for as
     * long as it stays valid — kept deliberately short, unlike the 1-hour
     * default S3StorageBackend::url() uses for an ordinary album's <img
     * src> (module spec: "a few minutes").
     */
    private const DELEGATED_PRESIGN_TTL = '+5 minutes';

    /**
     * @param int[] $linkedMemberIds
     */
    public function __construct(
        protected Environment $twig,
        private AlbumService $albumService,
        private MediaService $mediaService,
        private MediaRepository $mediaRepository,
        private MemberService $memberService,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService,
        private StorageBackendFactory $storageBackendFactory,
        private StorageLocationService $storageLocationService,
        // Both last, with safe defaults, so every existing positional
        // construction of this controller (tests included) keeps working
        // unchanged — same "append-only" discipline as
        // Core\Module\ModuleManifest's `requires` addition. An empty
        // registry and an empty linked-member list mean every delegated
        // album is denied, never accidentally allowed, until both are
        // actually wired.
        private DelegatedAlbumAccessRegistry $delegatedAlbumAccessRegistry = new DelegatedAlbumAccessRegistry(),
        private array $linkedMemberIds = []
    ) {
    }

    /**
     * GET /gallery — albums for the current/previous scout year, matching
     * the member's own sections or unit-wide (module spec). Chiefs and
     * above see every section's albums here too — same bypass show()
     * already grants via isVisible(), so a chief who can open a
     * section-scoped album by direct link isn't left unable to find it in
     * the listing that leads there.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $scoutYearIds = $this->relevantScoutYearIds();

        if (Role::fromString(AuthSession::getRole())->hasAccess(Role::CHIEF)) {
            $albums = array_values(array_filter(
                $this->albumService->findAllForManage(),
                fn(Album $a) => in_array($a->scoutYearId, $scoutYearIds, true)
            ));
        } else {
            $sectionIds = $this->linkedSectionIds(AuthSession::getEmail() ?? '');
            $albums = $this->albumService->findVisibleForMember($sectionIds, $scoutYearIds);
        }

        return $this->render('@gallery/list.html.twig', [
            'albums' => array_map(fn(Album $a) => $this->cardContext($a), $albums),
        ]);
    }

    /**
     * GET /gallery/{id} — local albums show their media grid; external
     * albums are just a link, so we send the browser straight to it
     * (module spec: "External albums are clickable — open the link in a
     * new tab" — the app itself never renders their content).
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null || $album->isDelegated()) {
            // A delegated album is reachable only through its owning
            // module — never gallery's own pages, direct id or not. Same
            // 404 (not 403) as an unknown id: this must not confirm the
            // album exists.
            return new Response('Not Found', 404);
        }
        if (!$this->isVisible($album)) {
            return new Response('Forbidden', 403);
        }
        if (!$album->isLocal()) {
            return $this->redirect((string) $album->externalUrl);
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        $location = $location !== null ? $this->storageLocationService->checkFresh($location) : null;
        $unavailable = $album->isMigrating() || ($location !== null && $location->lastCheckOk === false);

        $mediaRows = $this->mediaRepository->findByAlbumId($album->id);
        $media = $unavailable ? [] : array_map(fn(Media $m) => [
            'media' => $m,
            'thumb_url' => $this->mediaService->resolveUrl($m, $album, 'thumb'),
            'medium_url' => $this->mediaService->resolveUrl($m, $album, 'medium'),
            'large_url' => $this->mediaService->resolveUrl($m, $album, 'large'),
        ], $mediaRows);

        return $this->render('@gallery/album.html.twig', [
            'album' => $album,
            'media' => $media,
            'has_downloadable_media' => !$unavailable && count(array_filter($mediaRows, fn(Media $m) => $m->processingStatus === Media::STATUS_DONE)) > 0,
            'storage_unavailable' => $unavailable,
            'storage_unavailable_reason' => $album->isMigrating()
                ? 'Cet album est en cours de migration vers un autre emplacement de stockage.'
                : ($unavailable ? $location->lastCheckError : null),
        ]);
    }

    /**
     * GET /gallery/{id}/download — streams a ZIP of every successfully
     * processed media in a local album: the "large" rendition for photos,
     * and for videos the best available rendition (kept original, else
     * 1080p, else the always-present 720p transcode). Not offered for
     * external albums, which have no hosted media of their own to bundle.
     *
     * @param array<string, string> $params
     */
    public function downloadZip(Request $request, array $params): Response
    {
        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null || !$album->isLocal() || $album->isDelegated()) {
            return new Response('Not Found', 404);
        }
        if (!$this->isVisible($album)) {
            return new Response('Forbidden', 403);
        }
        if ($album->isMigrating()) {
            return new Response('Album en cours de migration.', 503);
        }

        $media = array_values(array_filter(
            $this->mediaRepository->findByAlbumId($album->id),
            fn(Media $m) => $m->processingStatus === Media::STATUS_DONE
        ));
        if ($media === []) {
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $tempZipPath = (string) tempnam(sys_get_temp_dir(), 'gallery_zip_');
        $storage = $this->storageBackendFactory->create($location);

        $zip = new \ZipArchive();
        $zip->open($tempZipPath, \ZipArchive::OVERWRITE);

        $usedNames = [];
        foreach ($media as $m) {
            $path = $this->bestDownloadPath($m);
            if ($path === null) {
                continue;
            }
            try {
                $contents = $storage->get($path);
            } catch (\RuntimeException) {
                continue;
            }
            $zip->addFromString($this->uniqueZipEntryName($m, $usedNames), $contents);
        }
        $zip->close();

        $zipContents = (string) file_get_contents($tempZipPath);
        @unlink($tempZipPath);

        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($album->title)) ?? 'album';

        return (new Response($zipContents))
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="' . trim($slug, '-') . '.zip"')
            ->setHeader('Content-Length', (string) strlen($zipContents));
    }

    /**
     * GET /gallery/media/{media_id}/{size} — streams a local-storage
     * rendition. Only reached for the local backend: Service\MediaService
     * ::resolveUrl() gives a direct S3/CDN URL instead when S3 is active,
     * so this route is never linked to in that case (still role-gated by
     * module.json's role_min: identified either way).
     *
     * A rendition's storage key never changes once processed (a re-upload
     * gets a new media id, never a rewritten key), so it doubles as a
     * stable ETag computed with no I/O — letting a conditional GET (every
     * plain page reload sends one) short-circuit to an empty 304 without
     * ever touching the storage backend, instead of re-reading/re-sending
     * the full file every single time.
     *
     * @param array<string, string> $params
     */
    public function serveMedia(Request $request, array $params): Response
    {
        $mediaId = (int) ($params['media_id'] ?? 0);
        $size = (string) ($params['size'] ?? '');

        $media = $this->mediaRepository->findById($mediaId);
        if ($media === null) {
            return new Response('Not Found', 404);
        }

        $album = $this->albumService->findById($media->albumId);
        if ($album === null) {
            return new Response('Not Found', 404);
        }

        if ($album->isDelegated()) {
            return $this->serveDelegatedMedia($album, $media, $size);
        }

        if ($album->isMigrating()) {
            return new Response('Album en cours de migration.', 503);
        }

        $path = match ($size) {
            'thumb' => $media->thumbPath,
            'medium' => $media->mediumPath,
            'large' => $media->largePath,
            'original' => $media->originalPath,
            default => null,
        };
        if ($path === null) {
            return new Response('Not Found', 404);
        }

        $etag = '"' . md5($path) . '"';
        $ifNoneMatch = trim((string) $request->getServer('HTTP_IF_NONE_MATCH', ''));
        if ($ifNoneMatch === $etag || $ifNoneMatch === '*') {
            return (new Response('', 304))
                ->setHeader('ETag', $etag)
                ->setHeader('Cache-Control', 'private, max-age=31536000');
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        try {
            $contents = $this->storageBackendFactory->create($location)->get($path);
        } catch (\RuntimeException) {
            return new Response('Not Found', 404);
        }

        $mimeType = $media->isVideo() && $size !== 'thumb' ? 'video/mp4' : 'image/jpeg';

        return (new Response($contents))
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Cache-Control', 'private, max-age=31536000')
            ->setHeader('ETag', $etag);
    }

    /**
     * The delegated-album path of serveMedia() above: the ownership
     * checker registry is consulted before anything else — before the
     * migration check, before resolving a size to a stored path, before
     * touching the storage backend at all — so a denial never depends on
     * (and never leaks through timing on) any of that. Fail-closed: an
     * owner_type with no registered checker is denied exactly like a
     * checker that actively refuses (Service\DelegatedAlbumAccessRegistry).
     *
     * No ETag/max-age caching here, unlike the ordinary path above — every
     * response, including the S3 redirect, carries
     * `Cache-Control: private, no-store` (module spec) so no shared cache
     * or service worker ever retains a delegated media response or the
     * presigned URL it points to.
     */
    private function serveDelegatedMedia(Album $album, Media $media, string $size): Response
    {
        \assert($album->ownerType !== null && $album->ownerId !== null);

        $role = Role::fromString(AuthSession::getRole());
        if (!$this->delegatedAlbumAccessRegistry->isAllowed($album->ownerType, $album->ownerId, $role, $this->linkedMemberIds)) {
            return new Response('Not Found', 404);
        }

        if ($album->isMigrating()) {
            return new Response('Album en cours de migration.', 503);
        }

        $path = match ($size) {
            'thumb' => $media->thumbPath,
            'medium' => $media->mediumPath,
            'large' => $media->largePath,
            'original' => $media->originalPath,
            default => null,
        };
        if ($path === null) {
            // Covers both an unknown $size and a still-pending/failed
            // media (Repository\Media's derived paths are null until
            // processing marks it done) — never a 500 either way.
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        if ($location->isS3()) {
            // Minted fresh for this one request, never stored or logged —
            // a presigned URL is a bearer credential for as long as it
            // stays valid, hence the short TTL (self::DELEGATED_PRESIGN_TTL)
            // instead of the 1-hour default an ordinary album's own
            // <img src> uses.
            $url = $this->storageBackendFactory->create($location)->url($path, self::DELEGATED_PRESIGN_TTL);
            return (new Response('', 302))
                ->setHeader('Location', $url)
                ->setHeader('Cache-Control', 'private, no-store');
        }

        try {
            $contents = $this->storageBackendFactory->create($location)->get($path);
        } catch (\RuntimeException) {
            return new Response('Not Found', 404);
        }

        $mimeType = $media->isVideo() && $size !== 'thumb' ? 'video/mp4' : 'image/jpeg';

        return (new Response($contents))
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @return int[]
     */
    private function linkedSectionIds(string $email): array
    {
        $ids = [];
        $allSections = $this->sectionService->getAllWithBranches();
        foreach ($this->relevantScoutYearIds() as $scoutYearId) {
            foreach ($this->memberService->getLinkedMembers($email, $scoutYearId) as $member) {
                foreach ($member->functions as $function) {
                    if ($function->sectionCode === null) {
                        continue;
                    }
                    foreach ($allSections as $section) {
                        if ($section['desk_code'] === $function->sectionCode) {
                            $ids[] = (int) $section['id'];
                        }
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @return int[]
     */
    private function relevantScoutYearIds(): array
    {
        $current = $this->scoutYearService->getCurrentYear();
        $years = $this->scoutYearService->getAll();

        $ids = [$current['id']];
        foreach ($years as $index => $year) {
            if ($year['id'] === $current['id']) {
                if ($index > 0) {
                    $ids[] = $years[$index - 1]['id'];
                }
                break;
            }
        }
        return $ids;
    }

    private function isVisible(Album $album): bool
    {
        if ($album->sectionId === null) {
            return true;
        }
        $email = AuthSession::getEmail() ?? '';
        return in_array($album->sectionId, $this->linkedSectionIds($email), true)
            || Role::fromString(AuthSession::getRole())->hasAccess(Role::CHIEF);
    }

    private function bestDownloadPath(Media $m): ?string
    {
        if ($m->isVideo()) {
            return $m->originalPath ?? $m->largePath ?? $m->mediumPath;
        }
        return $m->largePath ?? $m->mediumPath ?? $m->thumbPath;
    }

    /**
     * @param array<string, true> $usedNames
     */
    private function uniqueZipEntryName(Media $m, array &$usedNames): string
    {
        $ext = $m->isVideo() ? 'mp4' : 'jpg';
        $base = $m->originalFilename !== null
            ? pathinfo($m->originalFilename, PATHINFO_FILENAME)
            : 'media_' . $m->id;
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $base) ?: ('media_' . $m->id);

        $name = $base . '.' . $ext;
        for ($i = 2; isset($usedNames[$name]); $i++) {
            $name = $base . '_' . $i . '.' . $ext;
        }
        $usedNames[$name] = true;

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function cardContext(Album $album): array
    {
        $unavailable = false;
        $unavailableReason = null;
        if ($album->isLocal()) {
            if ($album->isMigrating()) {
                $unavailable = true;
                $unavailableReason = 'Cet album est en cours de migration vers un autre emplacement de stockage.';
            } else {
                $location = $this->storageLocationService->resolveLocationForAlbum($album);
                $location = $location !== null ? $this->storageLocationService->checkFresh($location) : null;
                $unavailable = $location !== null && $location->lastCheckOk === false;
                $unavailableReason = $unavailable ? $location->lastCheckError : null;
            }
        }

        $coverUrl = null;
        if (!$unavailable && $album->coverMediaId !== null) {
            $cover = $this->mediaRepository->findById($album->coverMediaId);
            $coverUrl = $cover !== null ? $this->mediaService->resolveUrl($cover, $album, 'thumb') : null;
        } elseif (!$album->isLocal()) {
            $coverUrl = $album->ogImageFileId !== null ? '/files/' . $album->ogImageFileId : null;
        }

        return [
            'album' => $album,
            'cover_url' => $coverUrl,
            'display_title' => $album->displayTitle(),
            'storage_unavailable' => $unavailable,
            'storage_unavailable_reason' => $unavailableReason,
        ];
    }
}
