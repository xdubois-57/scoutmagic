<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentService;
use Twig\Environment;

class UploadController extends AbstractController
{
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private UploadHandler $uploadHandler,
        private EditableContentService $editableContentService,
        private MemberPhotoService $memberPhotoService,
        private SectionPhotoService $sectionPhotoService,
        private SectionPhotoProcessor $sectionPhotoProcessor
    ) {
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * GET /upload — render the upload page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = (string) $request->getQuery('context', '');
        $key = (string) $request->getQuery('key', '');
        $returnUrl = (string) $request->getQuery('return', '/');

        return $this->render('upload/index.html.twig', [
            'context' => $context,
            'key' => $key,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * POST /upload — handle the upload.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
        }

        $context = (string) $request->getBody('context', '');
        $key = (string) $request->getBody('key', '');
        $returnUrl = (string) $request->getBody('return_url', '/');

        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            // Try camera input
            $uploadedFile = $request->getFile('file_camera');
        }

        if ($uploadedFile === null) {
            FlashMessage::set('error', 'Aucun fichier sélectionné.');
            return $this->redirect('/upload?context=' . urlencode($context) . '&key=' . urlencode($key) . '&return=' . urlencode($returnUrl));
        }

        try {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5 MB

            // section_photo (the Staffs page's group photo of a section's
            // chiefs) is always cropped to a fixed 4:3 landscape rendition
            // server-side before it's ever stored — see
            // Core\Photo\SectionPhotoProcessor. Done here, before
            // UploadHandler::handle(), so the file it stores (and the size
            // recorded in the files table) is already the final, processed
            // one.
            if ($context === 'section_photo') {
                $uploadedFile = $this->processSectionPhoto($uploadedFile, $allowedMimes);
            }

            // member_photo/section_photo uploads are scoped to a member or
            // section (not public site content) — see Core\Photo\
            // MemberPhotoService / SectionPhotoService. The Staffs page
            // itself requires at least 'intendant' to view, so a section
            // photo's own access floor matches that.
            $subDirectory = match ($context) {
                'member_photo' => 'core/member_photos',
                'section_photo' => 'core/section_photos',
                default => 'core/editable_contents',
            };
            $roleMin = match ($context) {
                'member_photo' => 'identified',
                'section_photo' => 'intendant',
                default => 'public',
            };

            $fileId = $this->uploadHandler->handle(
                $uploadedFile,
                $subDirectory,
                $allowedMimes,
                $maxSize,
                $roleMin,
                null,
                AuthSession::getUserAccountId()
            );

            // For editable_image context, update the editable content record
            if ($context === 'editable_image' && $key !== '') {
                $userId = AuthSession::getUserAccountId();
                if ($userId !== null) {
                    $this->editableContentService->set($key, (string) $fileId, 'image', $userId);
                }
            }

            // For member_photo context, key is "{memberId}:{scoutYearId}"
            if ($context === 'member_photo' && $key !== '') {
                [$memberIdStr, $yearIdStr] = array_pad(explode(':', $key, 2), 2, '');
                $memberId = (int) $memberIdStr;
                $scoutYearId = (int) $yearIdStr;
                $userId = AuthSession::getUserAccountId();
                if ($memberId > 0 && $scoutYearId > 0 && $userId !== null) {
                    $this->memberPhotoService->setPhoto($memberId, $scoutYearId, $fileId, $userId);
                    $this->journalService?->log(
                        'core',
                        'member_photo_updated',
                        'info',
                        'Photo d\'un membre modifiée',
                        ['member_id' => $memberId, 'scout_year_id' => $scoutYearId],
                        $userId
                    );
                }
            }

            // For section_photo context, key is "{sectionId}:{scoutYearId}"
            if ($context === 'section_photo' && $key !== '') {
                [$sectionIdStr, $yearIdStr] = array_pad(explode(':', $key, 2), 2, '');
                $sectionId = (int) $sectionIdStr;
                $scoutYearId = (int) $yearIdStr;
                $userId = AuthSession::getUserAccountId();
                if ($sectionId > 0 && $scoutYearId > 0 && $userId !== null) {
                    $this->sectionPhotoService->setPhoto($sectionId, $scoutYearId, $fileId, $userId);
                    $this->journalService?->log(
                        'core',
                        'section_photo_updated',
                        'info',
                        'Photo de staff d\'une section modifiée',
                        ['section_id' => $sectionId, 'scout_year_id' => $scoutYearId],
                        $userId
                    );
                }
            }

            FlashMessage::set('success', 'Fichier téléchargé avec succès.');

            return $this->redirect($returnUrl);
        } catch (UploadException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/upload?context=' . urlencode($context) . '&key=' . urlencode($key) . '&return=' . urlencode($returnUrl));
        }
    }

    /**
     * Crops/resizes the raw upload via Core\Photo\SectionPhotoProcessor
     * and points the returned array at a fresh temp file holding the
     * processed bytes — UploadHandler::handle() then stores exactly that
     * (already-final) file, so its own EXIF-stripping re-encode is the
     * only processing that happens after this point. Mime-type validation
     * itself is left entirely to UploadHandler::handle()'s own check right
     * after this call — a non-image (or disallowed) file is simply passed
     * through unprocessed and rejected there with its normal error
     * message, rather than duplicating that validation here.
     *
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @param array<string> $allowedMimes
     * @return array<string, mixed>
     * @throws UploadException when the source can't be decoded/processed
     */
    private function processSectionPhoto(array $uploadedFile, array $allowedMimes): array
    {
        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            return $uploadedFile;
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($mimeType, $allowedMimes, true)) {
            return $uploadedFile;
        }

        $processed = $this->sectionPhotoProcessor->process((string) file_get_contents($tmpName), $mimeType);

        $processedPath = tempnam(sys_get_temp_dir(), 'section_photo_');
        if ($processedPath === false) {
            throw new UploadException('Impossible de traiter cette image.');
        }
        file_put_contents($processedPath, $processed);

        $uploadedFile['tmp_name'] = $processedPath;
        $uploadedFile['size'] = strlen($processed);
        $uploadedFile['type'] = 'image/jpeg';

        return $uploadedFile;
    }
}
