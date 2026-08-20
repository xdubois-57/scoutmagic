<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;

/**
 * GD-based center-crop + resize for a section's staff photo (module spec:
 * "a traditional landscape ratio and the width being the max container
 * width") — every stored photo ends up the same 4:3 landscape shape
 * regardless of what was uploaded (portrait sources are center-cropped
 * too, never letterboxed/distorted), capped at MAX_WIDTH so it's exactly
 * as large as a full-container-width display ever needs and no larger.
 * Runs once, server-side, before the file is handed to UploadHandler —
 * unlike Modules\Gallery\Service\ImageProcessingService this produces a
 * single output size, there's no thumb/medium/large ladder here.
 */
class SectionPhotoProcessor
{
    private const ASPECT_RATIO = 4 / 3;
    private const MAX_WIDTH = 1600;
    private const QUALITY = 88;

    /**
     * @throws UploadException when the source can't be decoded
     */
    public function process(string $contents, string $mimeType): string
    {
        $image = $this->decode($contents, $mimeType);
        $image = $this->correctOrientation($image, $contents, $mimeType);

        $width = imagesx($image);
        $height = imagesy($image);
        $sourceRatio = $width / $height;

        if ($sourceRatio > self::ASPECT_RATIO) {
            // Wider than 4:3 — crop the sides.
            $cropHeight = $height;
            $cropWidth = (int) round($height * self::ASPECT_RATIO);
        } else {
            // Taller than 4:3 (including portrait) — crop top/bottom.
            $cropWidth = $width;
            $cropHeight = (int) round($width / self::ASPECT_RATIO);
        }
        $sx = (int) round(($width - $cropWidth) / 2);
        $sy = (int) round(($height - $cropHeight) / 2);

        $targetWidth = min(self::MAX_WIDTH, $cropWidth);
        $targetHeight = (int) round($targetWidth / self::ASPECT_RATIO);

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        // Flatten any transparency onto white — the output is always JPEG.
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, $sx, $sy, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        imagedestroy($image);

        ob_start();
        imagejpeg($resized, null, self::QUALITY);
        $encoded = (string) ob_get_clean();
        imagedestroy($resized);

        return $encoded;
    }

    /**
     * @return \GdImage
     */
    private function decode(string $contents, string $mimeType)
    {
        // Reject a decompression bomb before GD allocates for it (audit M7).
        try {
            \Core\Image\ImageDimensionGuard::assertWithinCeilingFromString($contents);
        } catch (\Core\Image\ImageDimensionException $e) {
            throw new UploadException($e->getMessage());
        }

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
