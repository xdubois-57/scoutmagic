<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

class UploadHandler
{
    public function __construct(
        private FileRepository $fileRepository,
        private string $storagePath
    ) {
    }

    /**
     * Handle a file upload.
     *
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @param string $subDirectory Destination within storage/
     * @param array<string> $allowedMimes Allowed MIME types
     * @param int $maxSizeBytes Maximum file size
     * @param string $roleMin Access role for the file
     * @param string|null $moduleId Owning module
     * @param int|null $createdBy User account ID
     * @return int File ID in the files table
     * @throws UploadException On validation failure
     */
    public function handle(
        array $uploadedFile,
        string $subDirectory,
        array $allowedMimes,
        int $maxSizeBytes,
        string $roleMin,
        ?string $moduleId = null,
        ?int $createdBy = null
    ): int {
        // Validate file exists and no upload error
        if (empty($uploadedFile['tmp_name']) || !is_string($uploadedFile['tmp_name'])) {
            throw new UploadException('Aucun fichier n\'a été envoyé.');
        }

        $tmpName = $uploadedFile['tmp_name'];
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_OK);

        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException('Erreur lors de l\'envoi du fichier (code ' . $error . ').');
        }

        // Check file size
        $size = (int) ($uploadedFile['size'] ?? 0);
        if ($size > $maxSizeBytes) {
            $maxMb = round($maxSizeBytes / 1024 / 1024, 1);
            throw new UploadException("Le fichier dépasse la taille maximale autorisée ({$maxMb} Mo).");
        }

        // Check true MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if ($mimeType === false || !in_array($mimeType, $allowedMimes, true)) {
            throw new UploadException('Type de fichier non autorisé (' . ($mimeType ?: 'inconnu') . ').');
        }

        // Generate random filename
        $extension = $this->extensionFromMime($mimeType);
        $randomName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = $subDirectory . '/' . $randomName;
        $targetDir = $this->storagePath . '/' . $subDirectory;
        $targetPath = $targetDir . '/' . $randomName;

        // Create directory if needed
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Strip EXIF from images by re-encoding
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $this->stripExifAndSave($tmpName, $targetPath, $mimeType);
        } else {
            // Move file
            if (!$this->moveFile($tmpName, $targetPath)) {
                throw new UploadException('Impossible de déplacer le fichier.');
            }
        }

        $originalName = (string) ($uploadedFile['name'] ?? 'file');
        $finalSize = (int) filesize($targetPath);

        return $this->fileRepository->create(
            $relativePath,
            $originalName,
            $mimeType,
            $finalSize,
            $roleMin,
            $moduleId,
            $createdBy
        );
    }

    /**
     * Strip EXIF metadata by re-encoding the image via GD.
     */
    private function stripExifAndSave(string $source, string $target, string $mimeType): void
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            'image/gif' => @imagecreatefromgif($source),
            default => false,
        };

        if ($image === false) {
            // Fallback: just move the file as-is
            if (!$this->moveFile($source, $target)) {
                throw new UploadException('Impossible de traiter l\'image.');
            }
            return;
        }

        // Bake the EXIF orientation (phone cameras store portrait shots as
        // landscape pixels + an Orientation tag) into the pixels themselves
        // before EXIF gets stripped below — otherwise the tag that would
        // have told a browser to auto-rotate the photo is gone and the
        // image is stuck sideways. Same pattern as the dedicated processors
        // (Core\Photo\SectionPhotoProcessor and friends), needed here too
        // since member_photo/age_branch_logo uploads come straight through
        // this generic path with no processor of their own.
        $image = $this->correctOrientation($image, $source, $mimeType);

        $result = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $target, 90),
            'image/png' => imagepng($image, $target),
            'image/webp' => imagewebp($image, $target, 90),
            'image/gif' => imagegif($image, $target),
            default => false,
        };

        imagedestroy($image);

        if (!$result) {
            throw new UploadException('Impossible de sauvegarder l\'image.');
        }
    }

    /**
     * @return \GdImage
     */
    private function correctOrientation(\GdImage $image, string $source, string $mimeType)
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = $exif['Orientation'] ?? 1;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }

    /**
     * Move an uploaded file (or copy in tests).
     */
    protected function moveFile(string $from, string $to): bool
    {
        if (is_uploaded_file($from)) {
            return move_uploaded_file($from, $to);
        }
        // For tests: just copy the file
        return copy($from, $to);
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
