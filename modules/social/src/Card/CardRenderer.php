<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Card;

use Core\Image\ImageDimensionException;
use Core\Image\ImageDimensionGuard;

/**
 * Composes the image that is published: a background, a title laid on it,
 * and the site's address at the foot.
 *
 * **Square, 1080 px.** Instagram accepts from 4:5 to 1.91:1 and crops its
 * grid to squares; Facebook shows a square whole. One format serves both
 * destinations, so the confirmation can show the one image that leaves.
 *
 * **The blur is not a choice made here.** The caller says whether the
 * background came from the gallery; if it did, it is blurred, always — the
 * only input is the strength, which is a site setting nobody edits
 * ({@see CardService::BLUR_SETTING}). The blur is of the whole image,
 * never of detected faces: a detector that misses one face is worse than
 * none.
 *
 * **Its strength is a proportion of the short side**, never pixels: a fixed
 * radius barely veils a 4000 px photo and wipes out an 800 px one. The
 * background is cropped to the square first, so « short side » means the
 * square's own side, and the same ratio gives the same result whatever the
 * camera.
 *
 * The address is the one thing that survives Instagram's refusal of links,
 * and it stays attached to the image when somebody reposts it elsewhere.
 */
class CardRenderer
{
    public const SIZE = 1080;

    private const QUALITY = 88;

    /** Inner margin, as a share of the side. */
    private const MARGIN = 0.06;

    private const TITLE_SIZE = 66;
    private const TITLE_MAX_LINES = 3;
    private const ADDRESS_SIZE = 34;

    /** The veil's alpha at the foot, in GD's scale: 127 is transparent, 0 opaque. */
    private const VEIL_ALPHA = 70;

    /** DejaVu Sans Bold, shipped with its licence beside it (resources/fonts/README.md). */
    public const DEFAULT_FONT = __DIR__ . '/../../resources/fonts/DejaVuSans-Bold.ttf';

    public function __construct(private readonly string $fontPath = self::DEFAULT_FONT)
    {
    }

    /**
     * @param string $contents   the background image's bytes
     * @param float  $blurRatio  the blur's radius as a share of the side;
     *                           ignored when `$blur` is false
     * @throws CardException when the background cannot be decoded
     */
    public function render(
        string $contents,
        string $title,
        string $address,
        bool $blur,
        float $blurRatio
    ): string {
        $card = $this->squareBackground($contents);

        if ($blur) {
            $card = self::blur($card, $blurRatio);
        }

        $this->veil($card);
        $this->writeText($card, $title, $address);

        ob_start();
        imagejpeg($card, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($card);

        return $jpeg;
    }

    /**
     * Blurs by shrinking then enlarging — the one approach GD can do in
     * well under a second on a phone photo. GD's own Gaussian filter is a
     * 3×3 kernel: reaching a radius of fifty pixels would take hundreds of
     * passes.
     *
     * The image is shrunk so that the radius becomes about two pixels,
     * smoothed there, then stretched back with bilinear resampling, which
     * spreads each of those pixels over the radius.
     */
    public static function blur(\GdImage $image, float $ratio): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $radius = max(1.0, min($width, $height) * max(0.0, $ratio));
        $factor = max(1.0, $radius / 2);

        $smallWidth = max(2, (int) round($width / $factor));
        $smallHeight = max(2, (int) round($height / $factor));

        $small = imagecreatetruecolor($smallWidth, $smallHeight);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $smallWidth, $smallHeight, $width, $height);
        // Smoothed at its smallest, where three passes cost nothing.
        for ($pass = 0; $pass < 3; $pass++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // Back up by doublings, smoothing at each one: a single stretch
        // of a tiny image leaves its pixel grid visible as soft squares.
        while ($smallWidth * 2 < $width && $smallHeight * 2 < $height) {
            $small = self::resized($small, $smallWidth * 2, $smallHeight * 2);
            $smallWidth *= 2;
            $smallHeight *= 2;
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $blurred = self::resized($small, $width, $height);
        imagedestroy($image);

        return $blurred;
    }

    private static function resized(\GdImage $image, int $width, int $height): \GdImage
    {
        $resized = imagecreatetruecolor($width, $height);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        imagedestroy($image);

        return $resized;
    }

    private function squareBackground(string $contents): \GdImage
    {
        try {
            ImageDimensionGuard::assertWithinCeilingFromString($contents);
        } catch (ImageDimensionException $e) {
            throw new CardException('Cette image est trop grande pour être publiée.', 0, $e);
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new CardException('Impossible de lire cette image.');
        }
        $source = self::upright($source, $contents);

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $sx = intdiv($width - $side, 2);
        $sy = intdiv($height - $side, 2);

        $card = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($card, 0, 0, (int) imagecolorallocate($card, 73, 80, 87));
        imagecopyresampled($card, $source, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($source);

        return $card;
    }

    /**
     * A darkening that grows towards the foot, where the text sits, so a
     * white title stays legible over a white sky.
     */
    private function veil(\GdImage $card): void
    {
        imagealphablending($card, true);
        $start = (int) (self::SIZE * 0.35);
        for ($y = $start; $y < self::SIZE; $y++) {
            $progress = ($y - $start) / (self::SIZE - $start);
            $alpha = (int) round(127 - (127 - self::VEIL_ALPHA) * $progress);
            $colour = (int) imagecolorallocatealpha($card, 0, 0, 0, $alpha);
            imageline($card, 0, $y, self::SIZE - 1, $y, $colour);
        }
    }

    private function writeText(\GdImage $card, string $title, string $address): void
    {
        $margin = (int) round(self::SIZE * self::MARGIN);
        $maxWidth = self::SIZE - 2 * $margin;
        $white = (int) imagecolorallocate($card, 255, 255, 255);
        $soft = (int) imagecolorallocate($card, 230, 233, 236);

        $baseline = self::SIZE - $margin;
        $address = trim($address);
        if ($address !== '') {
            $line = $this->fit($address, self::ADDRESS_SIZE, $maxWidth);
            imagettftext($card, self::ADDRESS_SIZE, 0, $margin, $baseline, $soft, $this->fontPath, $line);
            $baseline -= (int) round(self::ADDRESS_SIZE * 1.9);
        }

        $lines = $this->wrap(trim($title), self::TITLE_SIZE, $maxWidth);
        $lineHeight = (int) round(self::TITLE_SIZE * 1.25);
        foreach (array_reverse($lines) as $line) {
            imagettftext($card, self::TITLE_SIZE, 0, $margin, $baseline, $white, $this->fontPath, $line);
            $baseline -= $lineHeight;
        }
    }

    /**
     * Word-wrapped to the width, at most three lines, the last one ending
     * in an ellipsis when the title is longer than that.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $this->width($candidate, $size) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        $lines = array_map(fn (string $line): string => $this->fit($line, $size, $maxWidth), $lines);
        if (count($lines) > self::TITLE_MAX_LINES) {
            $lines = array_slice($lines, 0, self::TITLE_MAX_LINES);
            $last = self::TITLE_MAX_LINES - 1;
            $lines[$last] = $this->fit($lines[$last] . ' …', $size, $maxWidth, true);
        }

        return $lines;
    }

    /**
     * One line shortened, with an ellipsis, until it fits.
     */
    private function fit(string $line, int $size, int $maxWidth, bool $forceEllipsis = false): string
    {
        if (!$forceEllipsis && $this->width($line, $size) <= $maxWidth) {
            return $line;
        }

        $base = rtrim((string) preg_replace('/\s*…$/u', '', $line));
        while ($base !== '' && $this->width($base . '…', $size) > $maxWidth) {
            $base = rtrim(mb_substr($base, 0, -1));
        }

        return $base . '…';
    }

    private function width(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->fontPath, $text);

        return $box === false ? 0 : abs($box[2] - $box[0]);
    }

    /**
     * A phone photo is stored sideways with an EXIF note saying so.
     */
    private static function upright(\GdImage $image, string $contents): \GdImage
    {
        if (!function_exists('exif_read_data') || !str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }
}
