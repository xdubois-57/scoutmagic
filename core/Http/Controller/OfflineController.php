<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\UploadException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Module\StaffDirectoryProvider;
use Core\Photo\MemberPhotoService;
use Core\Photo\StaffThumbnailProcessor;
use Twig\Environment;

/**
 * Offline mode (Lot 3) — the one, narrow exception to "every download
 * goes through /files/{id}" (SECURITY.md §6): a square ~160px WebP
 * derivative of a staff member's current photo, for the trombinoscope's
 * offline pre-download. This confines the exception to one identified,
 * low-sensitivity resource — staff faces already visible to any
 * identified member via /trombinoscope — and is not a precedent for
 * bypassing FileAccessGuard elsewhere; every other file access must
 * still go through Core\Http\Controller\FileController.
 *
 * Both routes additionally gate on Core\Module\StaffDirectoryProvider
 * (nullable — the trombinoscope module may be disabled), so a member id
 * that isn't currently eligible staff can never be used to fetch an
 * arbitrary member's photo through this narrower route.
 */
class OfflineController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberPhotoService $memberPhotoService,
        private FileAccessGuard $fileAccessGuard,
        private ScoutYearService $scoutYearService,
        private StaffThumbnailProcessor $thumbnailProcessor,
        private string $storagePath,
        private EncryptedFileStorageService $encryptedFileStorageService,
        private ?StaffDirectoryProvider $staffDirectoryProvider = null
    ) {
    }

    /**
     * GET /api/offline/photo-manifest — every thumbnail URL the caller is
     * entitled to pre-download, across every section (module spec: "not
     * only theirs"). Members with no current photo are simply omitted —
     * nothing to fetch for them.
     *
     * @param array<string, string> $params
     */
    public function photoManifest(Request $request, array $params): Response
    {
        if ($this->staffDirectoryProvider === null) {
            return $this->json(['photos' => []]);
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        $photos = [];
        foreach ($this->staffDirectoryProvider->getAllEligibleStaffMemberIds($scoutYearId) as $memberId) {
            $fileId = $this->memberPhotoService->resolveFileId($memberId, $scoutYearId);
            if ($fileId !== null) {
                $photos[] = ['member_id' => $memberId, 'url' => '/api/offline/photo/' . $memberId];
            }
        }

        return $this->json(['photos' => $photos]);
    }

    /**
     * GET /api/offline/photo/{member_id} — the thumbnail itself, rendered
     * on demand (never persisted server-side — cheap enough per request,
     * same "on-demand, long Cache-Control" precedent as
     * FileController::thumbnail()). Long-lived Cache-Control since a
     * member's current-year photo doesn't change once set — a new photo
     * upload replaces the file id, so the URL itself only ever serves one
     * set of bytes anyway.
     *
     * @param array<string, string> $params
     */
    public function photo(Request $request, array $params): Response
    {
        $memberId = (int) ($params['member_id'] ?? 0);
        if ($memberId <= 0 || $this->staffDirectoryProvider === null) {
            return new Response('Not Found', 404);
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $eligible = $this->staffDirectoryProvider->getAllEligibleStaffMemberIds($scoutYearId);
        if (!in_array($memberId, $eligible, true)) {
            return new Response('Not Found', 404);
        }

        $fileId = $this->memberPhotoService->resolveFileId($memberId, $scoutYearId);
        if ($fileId === null) {
            return new Response('Not Found', 404);
        }

        $file = $this->fileAccessGuard->check($fileId);
        if ($file === null) {
            return new Response('Not Found', 404);
        }

        if ($file->encrypted) {
            try {
                $content = $this->encryptedFileStorageService->retrieve($file->id);
            } catch (\RuntimeException) {
                return new Response('Not Found', 404);
            }
        } else {
            $filePath = $this->storagePath . '/' . $file->relativePath;
            $content = file_exists($filePath) ? file_get_contents($filePath) : false;
            if ($content === false) {
                return new Response('Not Found', 404);
            }
        }

        try {
            $thumbnail = $this->thumbnailProcessor->process($content, $file->mimeType);
        } catch (UploadException) {
            return new Response('Not Found', 404);
        }

        return (new Response($thumbnail))
            ->setHeader('Content-Type', 'image/webp')
            ->setHeader('Cache-Control', 'private, max-age=604800')
            ->setHeader('Content-Length', (string) strlen($thumbnail));
    }
}
