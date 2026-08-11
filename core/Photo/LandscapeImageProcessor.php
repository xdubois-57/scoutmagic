<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;

/**
 * GD-based center-crop + resize to a fixed landscape aspect ratio —
 * generic, reusable anywhere a "banner"-style image is needed (introduced
 * for the generic editable_image() mechanism's uploads, e.g. the home
 * page's hero photo). Portrait AND square sources are center-cropped to
 * landscape too, never letterboxed/distorted, so every stored image ends
 * up the same shape regardless of what was uploaded — same philosophy as
 * Core\Photo\SectionPhotoProcessor (which stays a separate, section-
 * specific 4:3 class); this one is deliberately parameterized via its
 * constructor so a future caller can target a different ratio/width
 * without copy-pasting this logic again.
 */
class LandscapeImageProcessor
{
    // Matches modules/news/views/detail.html.twig's public article page
    // featured-image treatment (`w-100` inside the site's 1200px content
    // container — public/assets/css/app.css's --page-max-width —
    // `max-height:400px`, `object-fit:cover`): 1200:400 = 3:1. DEFAULT_MAX_WIDTH
    // is a bit above the container's own max-width for retina/high-DPI
    // displays, same headroom precedent as SectionPhotoProcessor's 1600
    // vs. its own ~1200px display context.
    private const DEFAULT_ASPECT_RATIO = 1200 / 400;
    private const DEFAULT_MAX_WIDTH = 1600;
    private const DEFAULT_QUALITY = 88;

    public function __construct(
        private float $aspectRatio = self::DEFAULT_ASPECT_RATIO,
        private int $maxWidth = self::DEFAULT_MAX_WIDTH,
        private int $quality = self::DEFAULT_QUALITY
    ) {
    }

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

        if ($sourceRatio > $this->aspectRatio) {
            // Wider than the target ratio — crop the sides.
            $cropHeight = $height;
            $cropWidth = (int) round($height * $this->aspectRatio);
        } else {
            // Taller than the target ratio (including square/portrait) — crop top/bottom.
            $cropWidth = $width;
            $cropHeight = (int) round($width / $this->aspectRatio);
        }
        $sx = (int) round(($width - $cropWidth) / 2);
        $sy = (int) round(($height - $cropHeight) / 2);

        $targetWidth = min($this->maxWidth, $cropWidth);
        $targetHeight = (int) round($targetWidth / $this->aspectRatio);

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        // Flatten any transparency onto white — the output is always JPEG.
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, $sx, $sy, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        imagedestroy($image);

        ob_start();
        imagejpeg($resized, null, $this->quality);
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
