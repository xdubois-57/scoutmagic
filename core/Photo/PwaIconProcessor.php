<?php

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;

/**
 * GD-based derivation of the four PWA icon sizes from one square source
 * logo — same before-storage processing precedent as SectionPhotoProcessor
 * (single input, fixed set of outputs), used both for a superadmin's
 * uploaded logo (Core\Http\Controller\UploadController's 'pwa_icon'
 * context) and to generate the shipped default icons under
 * public/assets/img/pwa/ (see scripts/generate-pwa-defaults.php).
 *
 * The source is always center-cropped to a square first — a superadmin is
 * asked for "one square logo" but nothing here trusts that literally.
 *
 * Maskable padding: Android (and other platforms honoring the W3C
 * "maskable icon" spec) applies its own shape mask (typically a circle)
 * over the icon's full canvas and can crop up to ~20% off each edge. The
 * spec's own safe zone is the inner 80% of the canvas, centered — so the
 * logo itself is scaled down to MASKABLE_LOGO_RATIO of the canvas and the
 * surrounding margin is filled solid with the declared background color
 * (never transparent — a maskable icon's background must be opaque, or
 * the platform's own mask shows through to whatever is behind it).
 */
class PwaIconProcessor
{
    private const SIZE_192 = 192;
    private const SIZE_512 = 512;
    private const SIZE_180 = 180;
    private const MASKABLE_LOGO_RATIO = 0.8;

    /**
     * @return array{192: string, 512: string, '512-maskable': string, 180: string} PNG-encoded bytes
     * @throws UploadException when the source can't be decoded
     */
    public function process(string $contents, string $mimeType, string $backgroundColorHex): array
    {
        $source = $this->decode($contents, $mimeType);
        $source = $this->correctOrientation($source, $contents, $mimeType);
        $square = $this->cropToSquare($source);
        imagedestroy($source);

        $result = [
            192 => $this->encodePng($this->resizeSquare($square, self::SIZE_192)),
            512 => $this->encodePng($this->resizeSquare($square, self::SIZE_512)),
            '512-maskable' => $this->encodePng($this->maskable($square, self::SIZE_512, $backgroundColorHex)),
            180 => $this->encodePng($this->resizeSquare($square, self::SIZE_180)),
        ];

        imagedestroy($square);

        return $result;
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

    /**
     * @param \GdImage $image
     * @return \GdImage
     */
    private function cropToSquare($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $sx = (int) round(($width - $side) / 2);
        $sy = (int) round(($height - $side) / 2);

        $square = imagecreatetruecolor($side, $side);
        imagesavealpha($square, true);
        $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
        imagefill($square, 0, 0, $transparent);
        imagecopy($square, $image, 0, 0, $sx, $sy, $side, $side);

        return $square;
    }

    /**
     * @param \GdImage $square
     * @return \GdImage
     */
    private function resizeSquare($square, int $size)
    {
        $resized = imagecreatetruecolor($size, $size);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        $sourceSize = imagesx($square);
        imagecopyresampled($resized, $square, 0, 0, 0, 0, $size, $size, $sourceSize, $sourceSize);

        return $resized;
    }

    /**
     * @param \GdImage $square
     * @return \GdImage
     */
    private function maskable($square, int $canvasSize, string $backgroundColorHex)
    {
        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        [$r, $g, $b] = $this->parseHexColor($backgroundColorHex);
        $bg = imagecolorallocate($canvas, $r, $g, $b);
        imagefill($canvas, 0, 0, $bg);

        $logoSize = (int) round($canvasSize * self::MASKABLE_LOGO_RATIO);
        $offset = (int) round(($canvasSize - $logoSize) / 2);

        $sourceSize = imagesx($square);
        imagecopyresampled($canvas, $square, $offset, $offset, 0, 0, $logoSize, $logoSize, $sourceSize, $sourceSize);

        return $canvas;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function parseHexColor(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [255, 255, 255];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param \GdImage $image
     */
    private function encodePng($image): string
    {
        ob_start();
        imagepng($image);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded;
    }
}
