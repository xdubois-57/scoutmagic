<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\FileRepository;
use Core\File\UploadException;

/**
 * Derivative pipeline for the core photo contexts (member_photo,
 * section_photo, editable_image, age_branch_logo) — same "no `files` row,
 * no new DB column, is_file() is the entire state" shape as
 * Core\Photo\UnitLogoService, but the derivatives here are siblings of the
 * stored original on disk (same storage subdirectory) rather than living
 * under their own fixed-filename storage root, since there can be any
 * number of originals rather than one unit-wide logo.
 *
 * Generated once, at upload (Core\Http\Controller\UploadController calls
 * generate() right after Core\File\UploadHandler::handle() has stored the
 * original) — never on demand, and never a fallback to the original when a
 * derivative is missing: a pre-existing photo uploaded before this
 * pipeline existed simply has no derivative until re-uploaded, and
 * rendering degrades accordingly (member_photo()'s initials placeholder,
 * or nothing at all for section_photo()/editable_image()).
 */
class ImageVariantService
{
    /**
     * The fixed, exhaustive vocabulary — Core\Http\Controller\FileController
     * ::variant() validates the URL's {variant} segment against this
     * before it is ever used to build a filesystem path.
     */
    private const VARIANTS = ['thumb', 'md'];

    public function __construct(
        private FileRepository $fileRepository,
        private ImageVariantProcessor $processor,
        private string $storagePath
    ) {
    }

    public static function isValidVariant(string $variant): bool
    {
        return in_array($variant, self::VARIANTS, true);
    }

    /**
     * Generates and persists a single derivative for an already-stored,
     * unencrypted file — a sibling of the original in the same storage
     * subdirectory. A no-op (never throws) when the file can't be found,
     * is encrypted (no core photo context ever is), can't be read back
     * from disk, or can't be decoded as an image — an upload must never
     * fail over a derivative that couldn't be produced.
     */
    public function generate(int $fileId, string $variant): void
    {
        if (!self::isValidVariant($variant)) {
            return;
        }

        $file = $this->fileRepository->findById($fileId);
        if ($file === null || $file->encrypted) {
            return;
        }

        $contents = @file_get_contents($this->storagePath . '/' . $file->relativePath);
        if ($contents === false) {
            return;
        }

        try {
            $derivative = $this->processor->process($variant, $contents, $file->mimeType);
        } catch (UploadException) {
            return;
        }

        $targetPath = $this->absoluteVariantPath($file->relativePath, $variant);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        file_put_contents($targetPath, $derivative);
    }

    /**
     * Absolute filesystem path to a derivative, or null when $variant
     * isn't in the fixed vocabulary or the derivative doesn't exist on
     * disk (no on-demand generation, no fallback to the original — the
     * caller renders nothing/a placeholder instead). $relativePath must
     * come from an already-resolved Core\File\FileRecord (Core\File\
     * FileAccessGuard::check() has already run) — never from raw request
     * input.
     */
    public function resolvePath(string $relativePath, string $variant): ?string
    {
        if (!self::isValidVariant($variant)) {
            return null;
        }

        $path = $this->absoluteVariantPath($relativePath, $variant);

        return is_file($path) ? $path : null;
    }

    private function absoluteVariantPath(string $relativePath, string $variant): string
    {
        return $this->storagePath . '/' . $this->siblingRelativePath($relativePath, $variant);
    }

    /**
     * "core/member_photos/{hex}.jpg" + "thumb" => "core/member_photos/{hex}.thumb.webp".
     */
    private function siblingRelativePath(string $relativePath, string $variant): string
    {
        $dir = dirname($relativePath);
        $base = pathinfo($relativePath, PATHINFO_FILENAME);
        $prefix = ($dir !== '' && $dir !== '.') ? $dir . '/' : '';

        return $prefix . $base . '.' . $variant . '.webp';
    }
}
