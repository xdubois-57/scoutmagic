<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Import\AgeBranchRepository;
use Core\View\EditableContentService;

/**
 * The image-upload pipeline of the site, in one place: validate, crop for the
 * context, store, generate the derivative, point the target at it.
 *
 * Extracted from Core\Http\Controller\UploadController::store(), which had
 * grown into a service wearing a controller's clothes. What is left there is
 * what genuinely belongs to a request — the CSRF token, the authorization
 * boundary (isUploadAuthorized()), the flash message, the redirect and the
 * journal entry. Everything below is what happens to the bytes, and it is the
 * same whether they arrive from a browser or from a command line.
 *
 * **Why it had to become a service.** The reference dataset's builder
 * (`tests/fixtures/reference-dataset/build.php`) has to put ~57 photos onto
 * members and sections through the real pipeline — the crop, the EXIF strip
 * and the derivative are exactly what makes those rows trustworthy, and
 * writing into `member_photos` by hand would skip all three. It cannot call
 * the controller: store() requires a valid CSRF token, an authenticated
 * session, ConfigurationMode and a `$_FILES` array, none of which exist in
 * CLI. Duplicating the pipeline in the builder was the alternative, and two
 * copies drift.
 *
 * The order of operations is not incidental and must not be rearranged:
 * cropping happens BEFORE UploadHandler::handle(), so the file it stores —
 * and the size it records in the `files` table — is already the final one.
 */
class PhotoIngestionService
{
    /** Every upload context the site knows. */
    public const CONTEXT_MEMBER_PHOTO = 'member_photo';
    public const CONTEXT_ACCOUNT_PHOTO = 'account_photo';
    public const CONTEXT_SECTION_PHOTO = 'section_photo';
    public const CONTEXT_EDITABLE_IMAGE = 'editable_image';
    public const CONTEXT_AGE_BRANCH_LOGO = 'age_branch_logo';
    public const CONTEXT_UNIT_LOGO = 'unit_logo';

    /** @var list<string> */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * 10 MB — raised from 5 MB once the five core photo contexts began
     * downscaling to WebP/2400px client-side before POSTing
     * (public/assets/js/upload.js). This remains the server-side floor for
     * anything the client-side step skips (already-small files) or cannot run
     * (JS disabled, non-browser client — the builder among them).
     */
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private UploadHandler $uploadHandler,
        private EditableContentService $editableContentService,
        private MemberPhotoService $memberPhotoService,
        private SectionPhotoService $sectionPhotoService,
        private SectionPhotoProcessor $sectionPhotoProcessor,
        private LandscapeImageProcessor $landscapeImageProcessor,
        private AgeBranchRepository $ageBranchRepository,
        private UnitLogoService $unitLogoService,
        private ImageVariantService $imageVariantService,
        // Trailing and optional for the same reason it is on the controller:
        // without it the account_photo context stores the file and simply
        // never points an account at it, which is the honest degradation for
        // a collaborator that is not wired.
        private ?AccountPhotoService $accountPhotoService = null,
    ) {
    }

    /**
     * Run one image through the pipeline.
     *
     * @param array<string, mixed> $uploadedFile a `$_FILES` entry, or the same
     *                                           shape built by a CLI caller
     * @param string               $key          context-specific target, e.g.
     *                                           "{memberId}:{scoutYearId}"
     * @param ?int                 $actorId      the account credited with the
     *                                           upload; null from a command
     *                                           line, which every collaborator
     *                                           below already accepts
     *
     * @throws UploadException on a rejected file
     */
    public function ingest(array $uploadedFile, string $context, string $key, ?int $actorId): PhotoIngestionResult
    {
        // unit_logo is deliberately never handed to UploadHandler at all: it
        // never becomes a `files` row / /files/{id} download, since every one
        // of those is fetched with no session. See UnitLogoService.
        if ($context === self::CONTEXT_UNIT_LOGO) {
            return $this->ingestUnitLogo($uploadedFile);
        }

        $uploadedFile = match ($context) {
            // Cropped to a fixed 4:3 landscape rendition server-side before it
            // is ever stored — see SectionPhotoProcessor.
            self::CONTEXT_SECTION_PHOTO => $this->cropBeforeStoring(
                $uploadedFile,
                fn (string $bytes, string $mime): string => $this->sectionPhotoProcessor->process($bytes, $mime),
                'section_photo_',
            ),
            // The generic site-wide "editable picture" mechanism, cropped to
            // the landscape ratio the public news article page uses.
            self::CONTEXT_EDITABLE_IMAGE => $this->cropBeforeStoring(
                $uploadedFile,
                fn (string $bytes, string $mime): string => $this->landscapeImageProcessor->process($bytes, $mime),
                'editable_image_',
            ),
            default => $uploadedFile,
        };

        $fileId = $this->uploadHandler->handle(
            $uploadedFile,
            self::subDirectoryFor($context),
            self::ALLOWED_MIMES,
            self::MAX_SIZE_BYTES,
            self::roleMinFor($context),
            null,
            $actorId,
        );

        // Derivative pipeline (ImageVariantService) — exactly one variant per
        // core photo context, generated once here and never regenerated on
        // demand. A context outside the map gets no derivative.
        $variant = self::variantFor($context);
        if ($variant !== null) {
            $this->imageVariantService->generate($fileId, $variant);
        }

        return $this->link($context, $key, $fileId, $actorId);
    }

    /**
     * `member_photo` and `section_photo` are scoped to a member or a section
     * (not public site content); `section_photo` is public because it is
     * rendered on the public Contact and Sections pages (ARCHITECTURE §8.21),
     * and a stricter floor would make FileAccessGuard deny it to exactly the
     * visitors those pages assume can see it. `account_photo` is somebody's
     * own face, shown to identified visitors and nobody else.
     */
    private static function subDirectoryFor(string $context): string
    {
        return match ($context) {
            self::CONTEXT_MEMBER_PHOTO => 'core/member_photos',
            self::CONTEXT_ACCOUNT_PHOTO => 'core/account_photos',
            self::CONTEXT_SECTION_PHOTO => 'core/section_photos',
            self::CONTEXT_AGE_BRANCH_LOGO => 'core/branch_logos',
            default => 'core/editable_contents',
        };
    }

    private static function roleMinFor(string $context): string
    {
        return match ($context) {
            self::CONTEXT_MEMBER_PHOTO, self::CONTEXT_ACCOUNT_PHOTO => 'identified',
            default => 'public',
        };
    }

    private static function variantFor(string $context): ?string
    {
        return match ($context) {
            self::CONTEXT_MEMBER_PHOTO, self::CONTEXT_ACCOUNT_PHOTO => 'thumb',
            self::CONTEXT_SECTION_PHOTO, self::CONTEXT_EDITABLE_IMAGE, self::CONTEXT_AGE_BRANCH_LOGO => 'md',
            default => null,
        };
    }

    /**
     * Point the context's target at the stored file.
     *
     * A malformed or empty key links nothing and is not an error: the file is
     * stored and the caller is told nothing was linked, which is what the
     * controller has always done.
     */
    private function link(string $context, string $key, int $fileId, ?int $actorId): PhotoIngestionResult
    {
        if ($key === '') {
            return new PhotoIngestionResult($fileId, false);
        }

        switch ($context) {
            case self::CONTEXT_EDITABLE_IMAGE:
                // EditableContentService records who edited, and has no null
                // author: a CLI caller must name an actor for this context.
                if ($actorId === null) {
                    return new PhotoIngestionResult($fileId, false);
                }
                $this->editableContentService->set($key, (string) $fileId, 'image', $actorId);

                return new PhotoIngestionResult($fileId, true);

            case self::CONTEXT_MEMBER_PHOTO:
                [$memberId, $scoutYearId] = self::splitPairKey($key);
                if ($memberId <= 0 || $scoutYearId <= 0) {
                    return new PhotoIngestionResult($fileId, false);
                }
                $this->memberPhotoService->setPhoto($memberId, $scoutYearId, $fileId, $actorId);

                return new PhotoIngestionResult($fileId, true, ['member_id' => $memberId, 'scout_year_id' => $scoutYearId]);

            case self::CONTEXT_SECTION_PHOTO:
                [$sectionId, $scoutYearId] = self::splitPairKey($key);
                if ($sectionId <= 0 || $scoutYearId <= 0) {
                    return new PhotoIngestionResult($fileId, false);
                }
                $this->sectionPhotoService->setPhoto($sectionId, $scoutYearId, $fileId, $actorId);

                return new PhotoIngestionResult($fileId, true, ['section_id' => $sectionId, 'scout_year_id' => $scoutYearId]);

            case self::CONTEXT_ACCOUNT_PHOTO:
                // Nobody sets somebody else's face, configuration mode
                // included. The caller's own authorization already enforces
                // this; the check is kept here as the second lock it has
                // always been, and it is why this one context refuses a null
                // actor outright.
                if ($actorId === null || (int) $key !== $actorId) {
                    return new PhotoIngestionResult($fileId, false);
                }
                $this->accountPhotoService?->setPhoto($actorId, $fileId);

                return new PhotoIngestionResult($fileId, true);

            case self::CONTEXT_AGE_BRANCH_LOGO:
                $ageBranchId = (int) $key;
                if ($ageBranchId <= 0) {
                    return new PhotoIngestionResult($fileId, false);
                }
                $this->ageBranchRepository->setLogo($ageBranchId, $fileId);

                return new PhotoIngestionResult($fileId, true, ['age_branch_id' => $ageBranchId]);

            default:
                return new PhotoIngestionResult($fileId, false);
        }
    }

    /**
     * @return array{int, int}
     */
    private static function splitPairKey(string $key): array
    {
        [$left, $right] = array_pad(explode(':', $key, 2), 2, '');

        return [(int) $left, (int) $right];
    }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    private function ingestUnitLogo(array $uploadedFile): PhotoIngestionResult
    {
        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new UploadException('Fichier invalide.');
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            throw new UploadException('Type de fichier non accepté — formats attendus : JPEG, PNG, GIF, WebP.');
        }

        $this->unitLogoService->storeUploadedLogo((string) file_get_contents($tmpName), $mimeType);

        return new PhotoIngestionResult(null, true);
    }

    /**
     * Crop before storing, into a NEW temporary file rather than over the
     * uploaded one: PHP owns `$_FILES['tmp_name']` and removes it at the end
     * of the request, and overwriting it in place would have this service
     * mutating something it does not own.
     *
     * An unreadable file or a MIME outside the allowlist returns the entry
     * untouched instead of throwing — UploadHandler::handle() is the one place
     * that rejects a file, with one message, and short-circuiting here would
     * give the same upload two different errors depending on its context.
     *
     * @param array<string, mixed>             $uploadedFile
     * @param callable(string, string): string $processor
     * @param string                           $prefix       temp-file prefix, for
     *                                                       readability in /tmp
     * @return array<string, mixed>
     */
    private function cropBeforeStoring(array $uploadedFile, callable $processor, string $prefix): array
    {
        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            return $uploadedFile;
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            return $uploadedFile;
        }

        $processed = $processor((string) file_get_contents($tmpName), $mimeType);

        $processedPath = tempnam(sys_get_temp_dir(), $prefix);
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
