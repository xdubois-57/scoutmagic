<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Image;

/**
 * Rejects a decompression-bomb image before it is ever handed to GD.
 *
 * Decoding allocates roughly width×height×4 bytes regardless of how small the
 * compressed file is, so a ~500 KB 40000×40000 PNG asks GD for ~6.4 GB and
 * OOM-kills the process (or the background photo task) before any downscaling
 * runs — reachable by any member who can upload (audit M7). A cheap
 * header-only dimension read (getimagesize / getimagesizefromstring) caps the
 * *input* pixel count up front, at every decode site.
 *
 * 50 megapixels comfortably clears any real photo a phone or camera produces
 * (a 48 MP phone shot is under it) while bounding a single decode to a few
 * hundred MB rather than several GB.
 */
final class ImageDimensionGuard
{
    public const MAX_PIXELS = 50_000_000;

    public static function assertWithinCeilingFromString(string $contents): void
    {
        $info = @getimagesizefromstring($contents);
        self::assert($info);
    }

    public static function assertWithinCeilingFromPath(string $path): void
    {
        $info = @getimagesize($path);
        self::assert($info);
    }

    /**
     * @param array<int, mixed>|false $info the getimagesize(...) result
     */
    private static function assert(array|false $info): void
    {
        // A header that doesn't parse as an image is not this guard's concern:
        // every caller already null-checks the subsequent decode, which will
        // fail with its own error. This guard only rejects genuine, decodable
        // images whose dimensions are hostile.
        if ($info === false || !isset($info[0], $info[1])) {
            return;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width * $height > self::MAX_PIXELS) {
            throw new ImageDimensionException(sprintf(
                'Image trop grande (%d×%d pixels) : la limite est de %d mégapixels.',
                $width,
                $height,
                intdiv(self::MAX_PIXELS, 1_000_000)
            ));
        }
    }
}
