<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * GD-based photo resize (no external binary — unlike video, this always
 * works). Produces three JPEG sizes from a source file: thumb (300px),
 * medium (1200px), large (capped at $maxDimension). EXIF orientation is
 * corrected first so a photo taken on its side displays upright.
 */
class ImageProcessingService
{
    private const THUMB_WIDTH = 300;
    private const MEDIUM_WIDTH = 1200;
    private const THUMB_QUALITY = 80;
    private const MEDIUM_QUALITY = 85;
    private const LARGE_QUALITY = 90;

    /**
     * @return array{thumb: string, medium: string, large: string, width: int, height: int}
     * @throws GalleryException when the source can't be decoded
     */
    public function process(string $contents, string $mimeType, int $maxDimension): array
    {
        $image = $this->decode($contents, $mimeType);
        $image = $this->correctOrientation($image, $contents, $mimeType);

        $width = imagesx($image);
        $height = imagesy($image);

        $large = $this->resizeAndEncode($image, min($maxDimension, max($width, $height)), self::LARGE_QUALITY);
        // If the original is already at or below medium size, medium and
        // large are the same image — no point re-encoding twice.
        $medium = $width <= self::MEDIUM_WIDTH
            ? $large
            : $this->resizeAndEncode($image, self::MEDIUM_WIDTH, self::MEDIUM_QUALITY);
        $thumb = $this->resizeAndEncode($image, self::THUMB_WIDTH, self::THUMB_QUALITY);

        imagedestroy($image);

        return ['thumb' => $thumb, 'medium' => $medium, 'large' => $large, 'width' => $width, 'height' => $height];
    }

    /**
     * @return \GdImage
     */
    private function decode(string $contents, string $mimeType)
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromstring($contents),
            'image/png' => @imagecreatefromstring($contents),
            'image/webp' => @imagecreatefromstring($contents),
            default => false,
        };

        if ($image === false) {
            throw new GalleryException('Impossible de décoder cette image.');
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

    /**
     * @param \GdImage $image
     */
    private function resizeAndEncode($image, int $targetLongSide, int $quality): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longSide = max($width, $height);

        if ($longSide <= $targetLongSide) {
            $targetWidth = $width;
            $targetHeight = $height;
        } else {
            $scale = $targetLongSide / $longSide;
            $targetWidth = (int) round($width * $scale);
            $targetHeight = (int) round($height * $scale);
        }

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        // Flatten any transparency onto white — the output is always JPEG.
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($resized, null, $quality);
        $encoded = (string) ob_get_clean();
        imagedestroy($resized);

        return $encoded;
    }
}
