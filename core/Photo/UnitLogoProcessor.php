<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;

/**
 * GD-based derivation of every unit-logo-derived asset — favicons (PNG
 * 16/32/48 plus a hand-packed .ico), the PWA manifest icons (192/512/
 * 512-maskable), the apple-touch-icon (180), and the small footer logo
 * (64) — from one square source logo. Same before-storage processing
 * precedent as SectionPhotoProcessor (single input, fixed set of
 * outputs), used both for a superadmin's uploaded logo
 * (Core\Http\Controller\UploadController's 'unit_logo' context, via
 * Core\Photo\UnitLogoService::storeUploadedLogo()/rederiveFromSource())
 * and to generate the shipped defaults under public/assets/img/pwa/
 * (scripts/generate-pwa-defaults.php).
 *
 * The source is always center-cropped to a square first — a superadmin is
 * asked for "one square logo" but nothing here trusts that literally.
 *
 * Opaque vs transparent outputs: favicons (16/32/48), the footer logo
 * (64), and the plain manifest icons (192/512) keep the source's alpha
 * channel — a favicon/app-icon rendered on an arbitrary background (a
 * browser tab, a dark-mode home screen) is expected to blend with it.
 * icon-180 (apple-touch-icon) and the maskable icon are the two
 * exceptions, both flattened onto an opaque canvas — see flattenOpaque()
 * below for why.
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
class UnitLogoProcessor
{
    private const SIZE_FAVICON_16 = 16;
    private const SIZE_FAVICON_32 = 32;
    private const SIZE_FAVICON_48 = 48;
    private const SIZE_LOGO_64 = 64;
    private const SIZE_180 = 180;
    private const SIZE_192 = 192;
    private const SIZE_512 = 512;
    private const MASKABLE_LOGO_RATIO = 0.8;

    /**
     * @return array{16: string, 32: string, 48: string, 64: string, 180: string, 192: string, 512: string, '512-maskable': string, ico: string} PNG-encoded bytes (ico is the packed .ico container)
     * @throws UploadException when the source can't be decoded
     */
    public function process(string $contents, string $mimeType, string $backgroundColorHex): array
    {
        $source = $this->decode($contents, $mimeType);
        $source = $this->correctOrientation($source, $contents, $mimeType);
        $square = $this->cropToSquare($source);
        imagedestroy($source);

        $favicon16 = $this->encodePng($this->resizeSquare($square, self::SIZE_FAVICON_16));
        $favicon32 = $this->encodePng($this->resizeSquare($square, self::SIZE_FAVICON_32));
        $favicon48 = $this->encodePng($this->resizeSquare($square, self::SIZE_FAVICON_48));

        $result = [
            16 => $favicon16,
            32 => $favicon32,
            48 => $favicon48,
            64 => $this->encodePng($this->resizeSquare($square, self::SIZE_LOGO_64)),
            180 => $this->encodePng($this->flattenOpaque($square, self::SIZE_180, 1.0, $backgroundColorHex)),
            192 => $this->encodePng($this->resizeSquare($square, self::SIZE_192)),
            512 => $this->encodePng($this->resizeSquare($square, self::SIZE_512)),
            '512-maskable' => $this->encodePng($this->flattenOpaque($square, self::SIZE_512, self::MASKABLE_LOGO_RATIO, $backgroundColorHex)),
        ];
        $result['ico'] = $this->packIco([
            self::SIZE_FAVICON_16 => $favicon16,
            self::SIZE_FAVICON_32 => $favicon32,
            self::SIZE_FAVICON_48 => $favicon48,
        ]);

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
     * Plain alpha-preserving resize — the source's transparency (if any)
     * is kept as-is, for outputs meant to blend with whatever background
     * they're displayed on (favicons, the footer logo, the plain manifest
     * icons).
     *
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
     * Flattens the logo onto an opaque, $backgroundColorHex-filled canvas
     * at $logoRatio of the canvas size (1.0 = full bleed, no margin — used
     * for icon-180; MASKABLE_LOGO_RATIO = 0.8 for the maskable icon's
     * safe-zone margin). Real bug, fixed here: icon-180 used to go through
     * the same alpha-preserving resizeSquare() as every other size, but
     * iOS's apple-touch-icon handling doesn't support alpha at all — any
     * transparency in the source became a solid BLACK square on the home
     * screen once installed, not a transparent one. The canvas is created
     * without imagesavealpha(), and GD's default alpha-blending mode
     * (enabled on both the source square and this destination canvas)
     * composites the source's semi-transparent pixels over the opaque
     * background instead of copying the alpha channel through — the same
     * mechanism the pre-existing maskable() derivation already relied on,
     * just previously never applied to icon-180.
     *
     * @param \GdImage $square
     * @return \GdImage
     */
    private function flattenOpaque($square, int $canvasSize, float $logoRatio, string $backgroundColorHex)
    {
        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        [$r, $g, $b] = $this->parseHexColor($backgroundColorHex);
        $bg = imagecolorallocate($canvas, $r, $g, $b);
        imagefill($canvas, 0, 0, $bg);

        $logoSize = (int) round($canvasSize * $logoRatio);
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

    /**
     * Hand-packs a favicon.ico container around already-PNG-encoded image
     * data — no new Composer dependency, no re-decoding. The modern ICO
     * format (supported by every browser still capable of falling back to
     * /favicon.ico at all — this is the legacy path, the <link rel="icon">
     * PNGs in base.html.twig's <head> win on every browser that honors
     * them) allows each embedded image to simply be a complete PNG file's
     * bytes rather than a decoded BMP/DIB, so this is just the ICONDIR +
     * one ICONDIRENTRY per size, followed by the PNG payloads back to
     * back:
     *
     * ICONDIR (6 bytes): reserved(WORD)=0, type(WORD)=1 (icon), count(WORD)
     * ICONDIRENTRY (16 bytes each): width(BYTE), height(BYTE),
     *   colorCount(BYTE)=0, reserved(BYTE)=0, planes(WORD)=1,
     *   bitCount(WORD)=32, bytesInResource(DWORD), imageOffset(DWORD)
     *
     * width/height of 0 conventionally means 256 in this format — never
     * reached here since $pngsBySize's largest entry is 48.
     *
     * @param array<int, string> $pngsBySize size (px) => PNG-encoded bytes, e.g. [16 => ..., 32 => ..., 48 => ...]
     */
    private function packIco(array $pngsBySize): string
    {
        $count = count($pngsBySize);
        $header = pack('vvv', 0, 1, $count);

        $offset = 6 + 16 * $count;
        $entries = '';
        $data = '';
        foreach ($pngsBySize as $size => $png) {
            $length = strlen($png);
            $entries .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $length, $offset);
            $data .= $png;
            $offset += $length;
        }

        return $header . $entries . $data;
    }
}
