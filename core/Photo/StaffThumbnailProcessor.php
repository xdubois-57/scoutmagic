<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;

/**
 * GD-based center-crop + resize for the offline trombinoscope
 * pre-download (Lot 3): square, ~160px, WebP. Mirrors
 * SectionPhotoProcessor's crop/orientation logic exactly, but the output
 * shape and size are deliberately different — this is a small derivative
 * meant to be pre-fetched in bulk (every section's staff, not just the
 * viewer's own) and cached client-side, never a substitute for the
 * full-resolution photo `/files/{id}` still serves live.
 *
 * Staff thumbnails only — never called for a member's own photo outside
 * the trombinoscope context (see Core\Http\Controller\OfflineController,
 * which gates every call through the current scout year's eligible-staff
 * list before ever invoking this).
 */
class StaffThumbnailProcessor
{
    private const SIZE = 160;
    private const QUALITY = 82;

    /**
     * @throws UploadException when the source can't be decoded
     */
    public function process(string $contents, string $mimeType): string
    {
        $image = $this->decode($contents, $mimeType);
        $image = $this->correctOrientation($image, $contents, $mimeType);

        $width = imagesx($image);
        $height = imagesy($image);
        $cropSize = min($width, $height);
        $sx = (int) round(($width - $cropSize) / 2);
        $sy = (int) round(($height - $cropSize) / 2);

        $resized = imagecreatetruecolor(self::SIZE, self::SIZE);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $cropSize, $cropSize);
        imagedestroy($image);

        ob_start();
        imagewebp($resized, null, self::QUALITY);
        $encoded = (string) ob_get_clean();
        imagedestroy($resized);

        return $encoded;
    }

    /**
     * @return \GdImage
     */
    private function decode(string $contents, string $mimeType)
    {
        $image = match ($mimeType) {
            'image/jpeg', 'image/png', 'image/webp', 'image/gif' => @imagecreatefromstring($contents),
            default => false,
        };

        if ($image === false) {
            throw new UploadException('Impossible de décoder cette image.');
        }

        return $image;
    }

    /**
     * @param \GdImage $image
     * @return \GdImage
     */
    private function correctOrientation($image, string $contents, string $mimeType)
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
        $orientation = $exif['Orientation'] ?? 1;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }
}
