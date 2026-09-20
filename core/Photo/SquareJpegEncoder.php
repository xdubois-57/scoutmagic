<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\Image\ImageDimensionException;
use Core\Image\ImageDimensionGuard;

/**
 * Decode, centre-crop to a square, resize, flatten onto white, encode as
 * JPEG — the one implementation of that gesture.
 *
 * The flattening matters: `Core\Photo\ImageVariantService`'s `thumb`
 * derivative preserves alpha and JPEG has none, so without an explicit
 * white ground a transparent corner comes out black.
 *
 * It exists because two features need a small, square, self-contained
 * JPEG of a member's portrait and neither can use a URL: the trombinoscope
 * PDF embeds one as a data URI (dompdf runs with `isRemoteEnabled` off),
 * and a contact card carries one inside the vCard itself.
 *
 * **Never throws.** A portrait that cannot be decoded returns null and the
 * caller draws its usual initials avatar; a stale file row must never fail
 * a whole document or a whole contact card. That includes a hostile one:
 * `SECURITY.md` §25's pixel ceiling is checked before the decode, and a
 * refusal comes back as the same null.
 */
final class SquareJpegEncoder
{
    /**
     * @param int $side    Square side, in pixels.
     * @param int $quality JPEG quality, 0-100.
     */
    public static function encode(string $bytes, int $side, int $quality): ?string
    {
        try {
            ImageDimensionGuard::assertWithinCeilingFromString($bytes);
        } catch (ImageDimensionException) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $crop = min($width, $height);

        $canvas = imagecreatetruecolor($side, $side);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $side, $side, $white);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            (int) round(($width - $crop) / 2),
            (int) round(($height - $crop) / 2),
            $side,
            $side,
            $crop,
            $crop
        );
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $encoded = ob_get_clean();
        imagedestroy($canvas);

        return $encoded === false || $encoded === '' ? null : $encoded;
    }
}
