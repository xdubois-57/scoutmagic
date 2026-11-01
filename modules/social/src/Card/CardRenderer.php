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
 * and the site's address beneath it.
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

    /**
     * **The text lives in the zone every common crop keeps** (issue #929).
     *
     * One square goes to every destination, and each of them crops it
     * differently, centred and in « cover » mode: a discussion thread shows
     * a 4:3 window (rows 135..945), Instagram's profile grid a 3:4 one
     * (columns 135..945), a Facebook preview about 1.91:1 (rows 257..823).
     * Their intersection is columns 135..945 by rows 257..823, and the whole
     * text block has to sit inside it — with an inset, for glyph overshoot,
     * the veil and rounding. These are test scenarios, not promises about
     * what a platform will do next year.
     */
    private const SAFE_LEFT = 135;
    private const SAFE_BOTTOM = 823;

    /** Between the zone's edge and the text: overshoot, rounding, a slightly different crop. */
    private const TEXT_INSET = 30;

    /**
     * The address's baseline: the zone's foot less the inset, less the 13 px
     * a descender reaches below the baseline at this size (0.236 em of 40 px,
     * rounded up) — 780.
     */
    private const ADDRESS_BASELINE = self::SAFE_BOTTOM - self::TEXT_INSET - 13;

    /** The widest a line of text may be: the zone's width less an inset on each side. */
    private const MAX_TEXT_WIDTH = self::SIZE - 2 * self::SAFE_LEFT - 2 * self::TEXT_INSET;

    private const TITLE_SIZE = 56;
    private const TITLE_MAX_LINES = 3;
    private const ADDRESS_SIZE = 30;

    /**
     * **The veil is a soft glow hugging the text, not a stripe across the
     * photo** (issue #929): full over the block's box grown by `VEIL_PAD`,
     * then fading to nothing over `VEIL_FEATHER` pixels — by distance to
     * that box, so its corners are round. The rest of the photo is left
     * alone, and a short title gets a short glow.
     *
     * It is computed on a grid of `VEIL_CELL`-pixel cells and stretched
     * back with a bilinear resample, in GD as in the browser: a smooth
     * function of the position needs no more resolution than that, and
     * sampling 108 × 108 cells is about twelve thousand evaluations, not a million.
     */
    private const VEIL_PAD = 20;
    private const VEIL_FEATHER = 160;
    private const VEIL_CELL = 10;

    /** The veil's alpha where it is fullest, in GD's scale: 127 is transparent, 0 opaque. */
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

        $layout = $this->layout($title, $address);
        $this->veil($card, $layout['box']);
        $this->writeText($card, $layout);

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
        $radius = min($width, $height) * max(0.0, $ratio);

        // **A radius under one pixel is no blur, and must do nothing.**
        // It used to do something: the radius was clamped up with
        // `max(1.0, …)`, so a ratio of 0 still fell through to the three
        // Gaussian passes below and cost a 1.4× loss of detail. That was
        // invisible while the ratio had a floor of 0.05 and could never
        // be 0 — the floor is gone in issue #706, IT-02, and the slider
        // reaches « Net ». Measured before this guard: a card asked for
        // sharp came back softened, while the browser's own engine drew
        // it sharp, so the fallback preview and the published card
        // disagreed about the one thing the slider promises.
        if ($radius < 1.0) {
            return $image;
        }

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
     * Where the block's text goes, and the box it occupies: the address on
     * its fixed baseline, the title above it, each line centred on the
     * card's vertical axis.
     *
     * Laid out from the bottom up, so the address is always in the same
     * place and a longer title grows upwards into the zone's spare height
     * rather than down towards its foot.
     *
     * The box is the ink's extent, estimated from the em: the first line's
     * top is a full em above its baseline and the last's foot a quarter em
     * below it — more than any glyph of the face reaches, which is the safe
     * side for a veil.
     *
     * @return array{
     *     address: ?array{text: string, x: int, baseline: int},
     *     title: list<array{text: string, x: int, baseline: int}>,
     *     box: ?array{left: float, top: float, right: float, bottom: float}
     * }
     */
    private function layout(string $title, string $address): array
    {
        $baseline = self::ADDRESS_BASELINE;
        $addressLine = null;
        $address = trim($address);
        $left = (float) self::SIZE;
        $right = 0.0;
        $top = null;
        $bottom = null;

        if ($address !== '') {
            $text = $this->fit($address, self::ADDRESS_SIZE, self::MAX_TEXT_WIDTH);
            $width = $this->width($text, self::ADDRESS_SIZE);
            $addressLine = [
                'text' => $text,
                'x' => $this->centredX($text, self::ADDRESS_SIZE),
                'baseline' => $baseline,
            ];
            $left = min($left, (self::SIZE - $width) / 2);
            $right = max($right, (self::SIZE + $width) / 2);
            $bottom = $baseline + 0.25 * self::ADDRESS_SIZE * 96 / 72;
            $top = $baseline - self::ADDRESS_SIZE * 96 / 72;
            $baseline -= (int) round(self::ADDRESS_SIZE * 1.9);
        }

        $titleLines = [];
        $lineHeight = (int) round(self::TITLE_SIZE * 1.25);
        $lines = $this->wrap(trim($title), self::TITLE_SIZE, self::MAX_TEXT_WIDTH);
        foreach (array_reverse($lines) as $line) {
            $width = $this->width($line, self::TITLE_SIZE);
            $titleLines[] = ['text' => $line, 'x' => $this->centredX($line, self::TITLE_SIZE), 'baseline' => $baseline];
            $left = min($left, (self::SIZE - $width) / 2);
            $right = max($right, (self::SIZE + $width) / 2);
            $bottom ??= $baseline + 0.25 * self::TITLE_SIZE * 96 / 72;
            $top = $baseline - self::TITLE_SIZE * 96 / 72;
            $baseline -= $lineHeight;
        }

        $box = $top === null || $bottom === null
            ? null
            : ['left' => $left, 'top' => (float) $top, 'right' => $right, 'bottom' => (float) $bottom];

        return ['address' => $addressLine, 'title' => $titleLines, 'box' => $box];
    }

    /**
     * How much of the veil is on one point, from 0 (none) to 1 (full): the
     * box grown by `VEIL_PAD` is full, and the strength falls to nothing
     * over `VEIL_FEATHER` pixels of distance to it, with a smoothstep so no
     * edge shows.
     *
     * The same function, constant for constant, as the browser's
     * (`veilStrength` in public/assets/js/social-card.js); both are tested
     * against the same sample points.
     *
     * @param array{left: float, top: float, right: float, bottom: float} $box
     */
    public static function veilStrength(float $x, float $y, array $box): float
    {
        $dx = max($box['left'] - self::VEIL_PAD - $x, 0.0, $x - ($box['right'] + self::VEIL_PAD));
        $dy = max($box['top'] - self::VEIL_PAD - $y, 0.0, $y - ($box['bottom'] + self::VEIL_PAD));
        $t = max(0.0, 1.0 - sqrt($dx * $dx + $dy * $dy) / self::VEIL_FEATHER);

        return $t * $t * (3 - 2 * $t);
    }

    /**
     * A glow of darkening behind the text, so a white title stays legible
     * over a white sky without dimming the rest of the photo.
     *
     * @param array{left: float, top: float, right: float, bottom: float}|null $box
     */
    private function veil(\GdImage $card, ?array $box): void
    {
        if ($box === null) {
            return;
        }

        $cells = intdiv(self::SIZE, self::VEIL_CELL);
        $small = imagecreatetruecolor($cells, $cells);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        for ($cy = 0; $cy < $cells; $cy++) {
            for ($cx = 0; $cx < $cells; $cx++) {
                $strength = self::veilStrength(($cx + 0.5) * self::VEIL_CELL, ($cy + 0.5) * self::VEIL_CELL, $box);
                $alpha = (int) round(127 - (127 - self::VEIL_ALPHA) * $strength);
                imagesetpixel($small, $cx, $cy, (int) imagecolorallocatealpha($small, 0, 0, 0, $alpha));
            }
        }

        // `imagecopyresampled()` stretches a grid this small in visible
        // steps; `imagescale()` interpolates, and keeps the alpha. Bilinear
        // on purpose: this build's bicubic crashes PHP on a small source.
        $overlay = imagescale($small, self::SIZE, self::SIZE, IMG_BILINEAR_FIXED);
        if ($overlay === false) {
            // Hard-edged, like the browser's fallback, but the text keeps
            // its contrast: a card with no veil at all is the worse one.
            imagedestroy($small);
            $this->plainVeil($card, $box);

            return;
        }
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);
        imagealphablending($card, true);
        imagecopy($card, $overlay, 0, 0, 0, 0, self::SIZE, self::SIZE);
        imagedestroy($small);
        imagedestroy($overlay);
    }

    /**
     * The veil without the glow: a darkened rectangle over the block's box
     * grown by `VEIL_PAD`, for the day the scaling that builds the glow
     * fails. The browser falls back to the same thing.
     *
     * @param array{left: float, top: float, right: float, bottom: float} $box
     */
    private function plainVeil(\GdImage $card, array $box): void
    {
        imagealphablending($card, true);
        imagefilledrectangle(
            $card,
            (int) round($box['left'] - self::VEIL_PAD),
            (int) round($box['top'] - self::VEIL_PAD),
            (int) round($box['right'] + self::VEIL_PAD),
            (int) round($box['bottom'] + self::VEIL_PAD),
            (int) imagecolorallocatealpha($card, 0, 0, 0, self::VEIL_ALPHA)
        );
    }

    /**
     * @param array{
     *     address: ?array{text: string, x: int, baseline: int},
     *     title: list<array{text: string, x: int, baseline: int}>,
     *     box: ?array{left: float, top: float, right: float, bottom: float}
     * } $layout
     */
    private function writeText(\GdImage $card, array $layout): void
    {
        $white = (int) imagecolorallocate($card, 255, 255, 255);
        $soft = (int) imagecolorallocate($card, 230, 233, 236);

        if ($layout['address'] !== null) {
            $line = $layout['address'];
            imagettftext(
                $card,
                self::ADDRESS_SIZE,
                0,
                $line['x'],
                $line['baseline'],
                $soft,
                $this->fontPath,
                $line['text']
            );
        }
        foreach ($layout['title'] as $line) {
            imagettftext(
                $card,
                self::TITLE_SIZE,
                0,
                $line['x'],
                $line['baseline'],
                $white,
                $this->fontPath,
                $line['text']
            );
        }
    }

    /** Where a line starts so that it is centred on the card. */
    private function centredX(string $line, int $size): int
    {
        return (int) round((self::SIZE - $this->width($line, $size)) / 2);
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
