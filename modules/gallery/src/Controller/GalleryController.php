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
use Modules\Gallery\Service\MediaFileName;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\Storage\StorageBackendInterface;
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
     * Largest slice served in one response. A browser opens a video with
     * `Range: bytes=0-`, i.e. "everything" — honouring that literally would
     * pull a whole 1080p transcode into PHP's memory. Answering with a capped
     * 206 instead is exactly what any streaming server does, and the player
     * simply asks for the next slice.
     */
    private const MAX_RANGE_BYTES = 8 * 1024 * 1024;

    /**
     * Ceiling on a whole-album ZIP. The archive has to be materialized as a
     * string to be returned (Core\Http\Response has no streaming body), so an
     * unbounded album is a guaranteed fatal on memory_limit — a clear refusal
     * beats a white page.
     */
    private const MAX_ZIP_BYTES = 512 * 1024 * 1024;

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

        [$unavailable, $unavailableReason] = $this->availability($album);

        $mediaRows = $this->mediaRepository->findByAlbumId($album->id);
        $media = $unavailable ? [] : array_map(fn(Media $m) => [
            'media' => $m,
            'thumb_url' => $this->mediaService->resolveUrl($m, $album, 'thumb'),
            'medium_url' => $this->mediaService->resolveUrl($m, $album, 'medium'),
        ], $mediaRows);

        return $this->render('@gallery/album.html.twig', [
            'album' => $album,
            'breadcrumb_current' => $album->title,
            'breadcrumb_trail' => [
                ['label' => 'Galerie', 'url' => '/gallery'],
            ],
            'media' => $media,
            'has_downloadable_media' => !$unavailable && count(array_filter($mediaRows, fn(Media $m) => $m->processingStatus === Media::STATUS_DONE)) > 0,
            'storage_unavailable' => $unavailable,
            'storage_unavailable_reason' => $unavailableReason,
        ]);
    }

    /**
     * GET /gallery/{id}/download — streams a ZIP of every successfully
     * processed media in a local album: the "large" rendition for photos,
     * and for videos the best available rendition (kept original, else
     * 1080p, else the always-present 720p transcode). Not offered for
     * external albums, which have no hosted media of their own to bundle.
     *
     * Each rendition is spooled to a temp file and handed to ZipArchive by
     * path rather than as a string: addFromString() keeps every entry in
     * memory until close(), so a large album used to hold the entire
     * uncompressed set at once, on top of the finished archive.
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

        $spoolDir = $this->makeTempDir('gallery_zip_spool_');
        $tempZipPath = (string) tempnam(sys_get_temp_dir(), 'gallery_zip_');
        $storage = $this->storageBackendFactory->create($location);

        // Set once the finished archive is handed to the Response to stream —
        // it then owns the temp file's deletion, so finally must not remove it
        // first (audit M10). Every error path leaves this false and finally
        // cleans up as before.
        $handedOff = false;

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
                return new Response('Archive indisponible.', 500);
            }

            $spooled = 0;
            $totalBytes = 0;
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

                $totalBytes += strlen($contents);
                if ($totalBytes > self::MAX_ZIP_BYTES) {
                    $zip->close();
                    return new Response(
                        'Cet album est trop volumineux pour être téléchargé en une seule archive — '
                        . 'ouvrez les médias un par un depuis la galerie.',
                        413
                    );
                }

                $spoolPath = $spoolDir . '/' . $spooled++;
                file_put_contents($spoolPath, $contents);
                unset($contents);
                // The same name this media downloads under on its own
                // (Service\MediaFileName): the id is part of it, so two
                // photos a phone both called IMG_1234 stay two entries
                // here AND two files in the folder somebody saves them
                // into one at a time.
                $zip->addFile($spoolPath, MediaFileName::forMedia($m));
            }
            $zip->close();

            // Stream the finished archive straight off disk rather than
            // reading the whole thing into a PHP string first (audit M10): the
            // renditions were already spooled to disk, so this removes the last
            // whole-archive buffer. The Response deletes the temp file after
            // streaming it.
            $handedOff = true;

            return (new Response())
                ->setBodyFile($tempZipPath, true)
                ->setHeader('Content-Type', 'application/zip')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $this->zipFileName($album) . '"')
                ->setHeader('Content-Length', (string) filesize($tempZipPath));
        } finally {
            // Runs on every exit path above, including the early refusals. The
            // temp zip is left for the Response only on the success path.
            if (!$handedOff) {
                @unlink($tempZipPath);
            }
            $this->removeDirectory($spoolDir);
        }
    }

    /**
     * GET /gallery/media/{media_id}/download — saves ONE media, at the
     * best quality this site keeps of it, under the name the album's own
     * zip gives it (Service\MediaFileName).
     *
     * Its own route rather than a `download` attribute on the rendition's
     * URL, for two reasons the lightbox used to live with:
     *
     * - `download` is ignored cross-origin, and with S3 storage the
     *   rendition URL IS cross-origin — so on those installations the
     *   button opened a tab instead of saving anything, which is why it
     *   had to be labelled vaguely and paired with a second button;
     * - the browser then names the file after the storage key, which is
     *   an internal path, and two photos saved from the same album could
     *   land on the same name and overwrite each other.
     *
     * Here the bytes always travel through the application, which costs
     * one stream on an S3 install and buys a `Content-Disposition` the
     * browser has to honour, with a name that is unique per media.
     *
     * Same visibility rules as serveMedia() below, delegated albums
     * included — the one difference being that a delegated album streams
     * rather than redirecting to a presigned URL, since a redirect would
     * lose the filename.
     *
     * @param array<string, string> $params
     */
    public function downloadMedia(Request $request, array $params): Response
    {
        $media = $this->mediaRepository->findById((int) ($params['media_id'] ?? 0));
        if ($media === null) {
            return new Response('Not Found', 404);
        }

        $album = $this->albumService->findById($media->albumId);
        if ($album === null) {
            return new Response('Not Found', 404);
        }

        if ($album->isDelegated()) {
            \assert($album->ownerType !== null && $album->ownerId !== null);
            $role = Role::fromString(AuthSession::getRole());
            if (!$this->delegatedAlbumAccessRegistry->isAllowed($album->ownerType, $album->ownerId, $role, $this->linkedMemberIds)) {
                return new Response('Not Found', 404);
            }
        } elseif (!$this->isVisible($album)) {
            return new Response('Forbidden', 403);
        }

        if ($album->isMigrating()) {
            return new Response('Album en cours de migration.', 503);
        }

        // The best rendition this site actually kept — the same choice
        // the album's zip makes for the same media, so the file somebody
        // saves one at a time is byte-for-byte the one they would have
        // got in the archive.
        $path = $this->bestDownloadPath($media);
        if ($path === null) {
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $backend = $this->storageBackendFactory->create($location);
        $mimeType = $media->isVideo() ? 'video/mp4' : 'image/jpeg';
        $disposition = 'attachment; filename="' . MediaFileName::forMedia($media) . '"';

        $localPath = $backend->localPath($path);
        if ($localPath !== null) {
            return (new Response())
                ->setBodyFile($localPath)
                ->setHeader('Content-Type', $mimeType)
                ->setHeader('Content-Length', (string) (filesize($localPath) ?: 0))
                ->setHeader('Content-Disposition', $disposition)
                ->setHeader('Cache-Control', 'private, no-store');
        }

        try {
            $contents = $backend->get($path);
        } catch (\RuntimeException) {
            return new Response('Not Found', 404);
        }

        return (new Response($contents))
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Content-Disposition', $disposition)
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * GET /gallery/media/{media_id}/{size} — streams a local-storage
     * rendition. Only reached for the local backend: Service\MediaService
     * ::resolveUrl() gives a direct S3/CDN URL instead when S3 is active,
     * so this route is never linked to in that case (still role-gated by
     * module.json's role_min: identified either way).
     *
     * module.json's role_min only establishes that the caller is an
     * identified member — it says nothing about WHICH albums they may see,
     * so this applies the same isVisible() section scoping show() and
     * downloadZip() do. Without it, sequential media ids made every
     * section-scoped album's photos readable by any logged-in member.
     * (A delegated album takes its own branch below, where
     * Service\DelegatedAlbumAccessRegistry decides instead.)
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

        if (!$this->isVisible($album)) {
            return new Response('Forbidden', 403);
        }
        if ($album->isMigrating()) {
            return new Response('Album en cours de migration.', 503);
        }

        $path = $this->pathForSize($media, $size);
        if ($path === null) {
            return new Response('Not Found', 404);
        }

        $etag = '"' . md5($path) . '"';
        $ifNoneMatch = trim((string) $request->getServer('HTTP_IF_NONE_MATCH', ''));
        if ($ifNoneMatch === $etag || $ifNoneMatch === '*') {
            return (new Response('', 304))
                ->setHeader('ETag', $etag)
                ->setHeader('Accept-Ranges', 'bytes')
                ->setHeader('Cache-Control', 'private, max-age=31536000');
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $backend = $this->storageBackendFactory->create($location);
        $mimeType = $this->mimeTypeFor($media, $size);

        $ranged = $this->serveRange($request, $backend, $path, $etag, $mimeType);
        if ($ranged !== null) {
            return $ranged;
        }

        // No Range header: stream a local object straight off disk rather than
        // reading a whole (up to 2 GB) video into a PHP string (audit M10).
        $localPath = $backend->localPath($path);
        if ($localPath !== null) {
            return (new Response())
                ->setBodyFile($localPath)
                ->setHeader('Content-Type', $mimeType)
                ->setHeader('Content-Length', (string) (filesize($localPath) ?: 0))
                ->setHeader('Accept-Ranges', 'bytes')
                ->setHeader('Cache-Control', 'private, max-age=31536000')
                ->setHeader('ETag', $etag);
        }

        try {
            $contents = $backend->get($path);
        } catch (\RuntimeException) {
            return new Response('Not Found', 404);
        }

        return (new Response($contents))
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Cache-Control', 'private, max-age=31536000')
            ->setHeader('ETag', $etag);
    }

    /**
     * A 206/416 answer to a `Range:` request, or null to fall through to the
     * ordinary full response (no Range header, an unusable one, or a backend
     * that can't report the object's size — RFC 9110 lets a server ignore
     * Range, so falling through is always a valid outcome).
     *
     * Without this a video was only ever served whole, which meant the whole
     * transcode in memory per request AND no seeking in the player: every
     * scrub restarted the download from byte zero.
     */
    private function serveRange(
        Request $request,
        StorageBackendInterface $backend,
        string $path,
        string $etag,
        string $mimeType
    ): ?Response {
        $header = trim((string) $request->getServer('HTTP_RANGE', ''));
        if ($header === '') {
            return null;
        }

        $total = $backend->size($path);
        if ($total === null || $total <= 0) {
            return null;
        }

        $range = self::parseByteRange($header, $total);
        if ($range === false) {
            return (new Response('', 416))
                ->setHeader('Content-Range', 'bytes */' . $total)
                ->setHeader('Accept-Ranges', 'bytes');
        }
        if ($range === null) {
            return null;
        }

        [$start, $end] = $range;
        $end = min($end, $start + self::MAX_RANGE_BYTES - 1, $total - 1);
        $length = $end - $start + 1;

        try {
            $bytes = $backend->getRange($path, $start, $length);
        } catch (\RuntimeException) {
            return new Response('Not Found', 404);
        }

        return (new Response($bytes, 206))
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Content-Length', (string) strlen($bytes))
            ->setHeader('Content-Range', "bytes {$start}-{$end}/{$total}")
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Cache-Control', 'private, max-age=31536000')
            ->setHeader('ETag', $etag);
    }

    /**
     * Parses a single-range `bytes=` header against a known object size.
     *
     * @return array{0: int, 1: int}|false|null [start, end] inclusive;
     *         false when the range is well-formed but entirely outside the
     *         object (a 416 is the useful answer); null when the header is
     *         something this endpoint does not implement (multi-range, other
     *         units, malformed) and the caller should just serve the whole
     *         thing.
     */
    private static function parseByteRange(string $header, int $total): array|false|null
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1) {
            return null;
        }

        [, $firstRaw, $lastRaw] = $matches;
        if ($firstRaw === '' && $lastRaw === '') {
            return null;
        }

        if ($firstRaw === '') {
            // Suffix form: the last N bytes.
            $suffix = (int) $lastRaw;
            if ($suffix <= 0) {
                return false;
            }
            $start = max(0, $total - $suffix);
            return [$start, $total - 1];
        }

        $start = (int) $firstRaw;
        if ($start >= $total) {
            return false;
        }
        $end = $lastRaw === '' ? $total - 1 : min((int) $lastRaw, $total - 1);
        if ($end < $start) {
            return false;
        }

        return [$start, $end];
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

        // Covers both an unknown $size and a still-pending/failed media
        // (Repository\Media's derived paths are null until processing
        // marks it done) — never a 500 either way.
        $path = $this->pathForSize($media, $size);
        if ($path === null) {
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        // Service\DelegatedAlbumService::ensureAlbum() refuses to create a
        // delegated album on a location with a public URL, because a public
        // URL is world-readable and defeats the access control entirely. That
        // invariant was only enforced at creation time: a superadmin adding
        // an s3_public_url to a location later would silently turn the short
        // presign below into a permanent, unauthenticated link (see
        // S3StorageBackend::url(), which ignores the TTL when a public URL is
        // set). Re-assert it here, where the bytes are actually handed out.
        if ($location->s3PublicUrl !== null && $location->s3PublicUrl !== '') {
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

        return (new Response($contents))
            ->setHeader('Content-Type', $this->mimeTypeFor($media, $size))
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * The stored rendition path for a given size — null for an unknown
     * $size or one that hasn't finished processing yet
     * (Repository\Media's thumb/medium/large/original paths stay null
     * until Task\ProcessPhotoHandler/ProcessVideoHandler mark it done).
     * Shared by both the ordinary and the delegated serving path above.
     */
    private function pathForSize(Media $media, string $size): ?string
    {
        // Delegates rather than duplicating the mapping: Service\MediaService
        // ::resolveUrl() decides which sizes exist for the <img src> side, and
        // the two must never disagree on it.
        return MediaService::pathForSize($media, $size);
    }

    /**
     * Thumbs are always a JPEG (even for a video, whose thumb is an
     * extracted frame) — every other rendition follows the media's own
     * type. Shared by both the ordinary and the delegated serving path.
     */
    private function mimeTypeFor(Media $media, string $size): string
    {
        return $media->isVideo() && $size !== 'thumb' ? 'video/mp4' : 'image/jpeg';
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

    /**
     * Whether an album's media can be shown right now, and why not when they
     * can't — a migration in flight, or a storage location failing its
     * (TTL-cached) health check.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function availability(Album $album): array
    {
        if (!$album->isLocal()) {
            return [false, null];
        }
        if ($album->isMigrating()) {
            return [true, 'Cet album est en cours de migration vers un autre emplacement de stockage.'];
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return [false, null];
        }

        $location = $this->storageLocationService->checkFresh($location);
        return $location->lastCheckOk === false
            ? [true, $location->lastCheckError]
            : [false, null];
    }

    /**
     * A safe, non-empty download filename. A title made entirely of
     * non-ASCII characters (or punctuation) slugified to the empty string,
     * which produced a bare ".zip" the browser had nothing to do with.
     */
    private function zipFileName(Album $album): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($album->title)), '-');

        return ($slug !== '' ? $slug : 'album-' . $album->id) . '.zip';
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    private function bestDownloadPath(Media $m): ?string
    {
        if ($m->isVideo()) {
            return $m->originalPath ?? $m->largePath ?? $m->mediumPath;
        }
        return $m->largePath ?? $m->mediumPath ?? $m->thumbPath;
    }


    /**
     * @return array<string, mixed>
     */
    private function cardContext(Album $album): array
    {
        [$unavailable, $unavailableReason] = $this->availability($album);

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
