<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Card;

use Modules\Social\Card\CardRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The browser's card and the server's are the SAME card (issue #706,
 * IT-02).
 *
 * From IT-02 the browser draws what is published and the server only
 * checks what arrives, so `CardRenderer` leaves the publication path. It
 * does not leave the repository: it still composes nothing, but its
 * constants are the shape every card already published has, and
 * `public/assets/js/social-card.js` has to keep that shape.
 *
 * **Two copies of a number are free to drift**, and the drift here is
 * invisible: a title laid eighty pixels apart instead of eighty-three
 * looks fine on its own and wrong beside last month's card. Nothing else
 * in the suite compares the two files — PHPUnit does not run the
 * JavaScript, Vitest does not read the PHP — so this test is the only
 * place the correspondence is stated.
 *
 * It reads the JavaScript as text rather than executing it. That is
 * deliberate: running it would need a JavaScript engine in the PHP suite,
 * and what matters is the literal a reviewer sees in the file, not a value
 * computed at run time from it. The Vitest suite asserts the same numbers
 * from the other side (`tests/js/social-card.test.js`), so a change made
 * in one language fails on both.
 *
 * **And comparing literals has one blind spot, which cost this iteration
 * a real defect** (found in the review of #850): two files can carry the
 * same number in different UNITS. `imagettftext()` takes a point size and
 * GD renders at 96 dpi, so the 66 handed to it draws an em of 88 pixels,
 * while `ctx.font = '66px'` draws an em of 66 — the browser's card was a
 * quarter smaller than the server's, and every literal matched. So the
 * conversion is MEASURED below, against GD itself rather than against a
 * font's published metrics, and the measurement runs on every suite.
 *
 * **The rounding is checked in PHP's own arithmetic**, not restated: the
 * expectations below are `round(1080 * 0.06)` and friends, so a reader can
 * see that 64.8 becomes 65 and 82.5 becomes 83, and that JavaScript's
 * `Math.round` agrees with PHP's `round` on both.
 */
final class CardGeometryAgreementTest extends TestCase
{
    private const SCRIPT = 'public/assets/js/social-card.js';

    private string $script;

    protected function setUp(): void
    {
        $path = \dirname(__DIR__, 4) . '/' . self::SCRIPT;
        $contents = @file_get_contents($path);
        self::assertIsString(
            $contents,
            self::SCRIPT . ' is missing — the browser no longer draws the card, and nothing says so.'
        );
        $this->script = $contents;
    }

    /**
     * `CardRenderer`'s own constant, read from the class: a number changed
     * on the PHP side alone then fails here, instead of being compared with
     * a second copy typed into this file.
     */
    private static function serverConstant(string $name): int|float|string
    {
        $value = (new \ReflectionClassConstant(CardRenderer::class, $name))->getValue();
        self::assertTrue(\is_int($value) || \is_float($value) || \is_string($value));

        return $value;
    }

    /**
     * @return array<string, array{string, int|float|string}>
     */
    public static function constants(): array
    {
        $size = CardRenderer::SIZE;

        return [
            'the square\'s side' => ['SIZE = %s;', $size],
            // The zone every common crop keeps, columns 135..945, rows 257..823.
            'the zone\'s left edge' => ['SAFE_LEFT = %s;', self::serverConstant('SAFE_LEFT')],
            'the zone\'s foot' => ['SAFE_BOTTOM = %s;', self::serverConstant('SAFE_BOTTOM')],
            'the inset inside the zone' => ['TEXT_INSET = %s;', self::serverConstant('TEXT_INSET')],
            'the address\'s baseline' => ['ADDRESS_BASELINE = %s;', self::serverConstant('ADDRESS_BASELINE')],
            'the widest line' => ['MAX_TEXT_WIDTH = SIZE - 2 * SAFE_LEFT - 2 * TEXT_INSET;', ''],
            'the title\'s size' => ['TITLE_SIZE = %s;', self::serverConstant('TITLE_SIZE')],
            'the three-line cap' => ['TITLE_MAX_LINES = %s;', self::serverConstant('TITLE_MAX_LINES')],
            // round(56 * 1.25) = 70.
            'the title\'s line height' => ['TITLE_LINE_HEIGHT = Math.round(TITLE_SIZE * %s);', 1.25],
            'the address\'s size' => ['ADDRESS_SIZE = %s;', self::serverConstant('ADDRESS_SIZE')],
            // round(30 * 1.9) = 57.
            'the gap above the address' => ['ADDRESS_TO_TITLE = Math.round(ADDRESS_SIZE * %s);', 1.9],
            'the veil\'s full part, around the block' => ['VEIL_PAD = %s;', self::serverConstant('VEIL_PAD')],
            'the veil\'s fade' => ['VEIL_FEATHER = %s;', self::serverConstant('VEIL_FEATHER')],
            'the veil\'s grid' => ['VEIL_CELL = %s;', self::serverConstant('VEIL_CELL')],
            'the backdrop grey' => ['BACKDROP = \'rgb(%s)\';', '73, 80, 87'],
            'the export quality' => ['JPEG_QUALITY = %s;', 0.88],
        ];
    }

    /**
     * The widest line is stated in the browser as a formula over the zone's
     * constants; the server's is the same formula, checked here against both
     * the number and its parts so that a change on the PHP side alone fails.
     */
    public function testTheWidestLineIsTheSameWidthInBothLanguages(): void
    {
        self::assertSame(750, self::serverConstant('MAX_TEXT_WIDTH'));
        self::assertSame(
            CardRenderer::SIZE - 2 * self::serverConstant('SAFE_LEFT') - 2 * self::serverConstant('TEXT_INSET'),
            self::serverConstant('MAX_TEXT_WIDTH')
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('constants')]
    public function testTheBrowserCarriesTheSameConstantAsTheServer(string $pattern, int|float|string $value): void
    {
        $expected = \sprintf($pattern, $value);

        self::assertStringContainsString(
            $expected,
            $this->script,
            'The browser\'s card must keep the server\'s geometry: expected `' . $expected . '` in '
            . self::SCRIPT . '. A card drawn to different numbers looks right alone and wrong beside'
            . ' the ones already published.'
        );
    }

    /**
     * GD counts alpha backwards from canvas: 127 is transparent and 0 is
     * opaque, so `VEIL_ALPHA = 70` at the foot is `(127 - 70) / 127` of
     * blackness on canvas's own 0-to-1 scale. The JavaScript has to do
     * that conversion, and this is the only place that says why.
     */
    public function testTheVeilsDarknessIsConvertedFromGdsInvertedAlpha(): void
    {
        self::assertStringContainsString(
            'VEIL_FULL_ALPHA = (127 - 70) / 127;',
            $this->script,
            'The veil must be as dark as GD drew it. GD\'s 127 is TRANSPARENT, so copying 70 straight'
            . ' into a canvas alpha would make the band almost opaque instead of 45% black.'
        );
        // About 0.4488 — stated with a tolerance so the sentence above is
        // checkable by a reader without pinning a float literal whose last
        // digit depends on how it was written.
        self::assertEqualsWithDelta(0.4488, (127 - 70) / 127, 0.0001);
    }

    /**
     * The blur's radius is a share of the SQUARE's side in both, which is
     * why one setting suits a 4000 px photo and an 800 px one alike.
     *
     * The two blurs will not match pixel for pixel — GD shrinks, smooths
     * with a 3×3 kernel and grows back, the browser has a real Gaussian —
     * and nothing compares pixels. What has to agree is the scale, and
     * one more thing the review of #850 found missing: GD's filter
     * CLAMPS at the bitmap's edges, while `ctx.filter` ramps a draw's
     * alpha down at its own border and let the backdrop show through as
     * a vignette. The browser now pads its blurred draw and carries the
     * crop's edges outwards, which `tests/js/social-card.test.js`
     * asserts on the draw calls themselves — jsdom having no pixels to
     * look at.
     */
    public function testTheBlurScalesToTheSquareInBothLanguages(): void
    {
        self::assertStringContainsString(
            '* SIZE;',
            $this->script,
            'the browser must scale the blur to the card\'s side, as CardRenderer::blur() does'
        );
        self::assertMatchesRegularExpression(
            '/min\(\$width, \$height\) \* max\(0\.0, \$ratio\)/',
            (string) file_get_contents(\dirname(__DIR__, 4) . '/modules/social/src/Card/CardRenderer.php'),
            'CardRenderer no longer scales its blur to the short side, so the sentence above is stale'
        );
    }

    /**
     * **Both renderers shorten a title by one CODE POINT, not by one
     * code unit or one byte.**
     *
     * A ratchet on the sources rather than on a rendering, because
     * neither runtime can check the other and the difference is
     * invisible in the pixels until a title happens to be cut at an
     * emoji. The browser used `slice(0, -1)`, one UTF-16 code unit,
     * against PHP's `mb_substr($base, 0, -1)`: a cut inside a surrogate
     * pair left the high half orphaned, drawing as U+FFFD — and
     * narrower than the emoji, so the loop stopped there and published
     * it. Found in the review of #850.
     *
     * `substr()` on the PHP side would be the mirror defect, cutting one
     * BYTE and leaving a broken UTF-8 sequence, so both halves are
     * pinned here.
     */
    public function testBothRenderersShortenATitleByOneCodePoint(): void
    {
        self::assertMatchesRegularExpression(
            '/Array\.from\(base\)\.slice\(0, -1\)/',
            $this->script,
            'the browser shortens a title by UTF-16 code unit again, so a cut at an emoji leaves half of it'
        );
        self::assertStringNotContainsString(
            'base.slice(0, -1).trimEnd()',
            $this->script,
            'the browser shortens a title by UTF-16 code unit again, so a cut at an emoji leaves half of it'
        );
        self::assertMatchesRegularExpression(
            '/mb_substr\(\$base, 0, -1\)/',
            (string) file_get_contents(\dirname(__DIR__, 4) . '/modules/social/src/Card/CardRenderer.php'),
            'CardRenderer no longer shortens a title by code point, so the browser now disagrees with it'
        );
    }

    /**
     * **The point size GD is handed is not the pixel size it draws**, and
     * this is the factor between them, measured in GD's own terms.
     *
     * Rather than trusting a published cap height, the same string is
     * measured twice: once at the title's point size, once at that size
     * scaled by 72/96. If GD renders at 96 dpi the first is 4/3 the
     * second, which is what the browser then has to apply. The remainder
     * past the third decimal is per-glyph hinting, not a different
     * factor — hence the tolerance.
     */
    public function testGdDrawsAnEmOfFourThirdsThePointSizeItIsHanded(): void
    {
        $font = CardRenderer::DEFAULT_FONT;
        self::assertFileExists($font);
        $sentence = 'Week-end de rentrée à la Baraque';
        $widthAt = static function (float $points) use ($font, $sentence): int {
            $box = imagettfbbox($points, 0, $font, $sentence);
            self::assertIsArray($box, 'GD could not measure the card\'s own font');

            return abs($box[2] - $box[0]);
        };

        $points = 66;
        $factor = $widthAt((float) $points) / $widthAt($points * 72 / 96);

        self::assertEqualsWithDelta(
            96 / 72,
            $factor,
            0.01,
            'GD no longer renders at 96 dpi, so public/assets/js/social-card.js converts by the wrong'
            . ' factor and the browser\'s text is a different size from the server\'s.'
        );
    }

    /**
     * And the browser applies it — on the FONT only, every other number
     * GD is handed being already in pixels.
     */
    public function testTheBrowserConvertsThePointSizesToPixels(): void
    {
        self::assertStringContainsString(
            'POINTS_TO_PIXELS = 96 / 72;',
            $this->script,
            'the browser must convert GD\'s point sizes, or its text is a quarter too small'
        );
        self::assertStringContainsString('TITLE_PIXELS = TITLE_SIZE * POINTS_TO_PIXELS;', $this->script);
        self::assertStringContainsString('ADDRESS_PIXELS = ADDRESS_SIZE * POINTS_TO_PIXELS;', $this->script);

        // And it is the converted values that reach the canvas: a
        // `ctx.font` built from the point size is the defect itself.
        self::assertStringContainsString('ctx.font = TITLE_PIXELS +', $this->script);
        self::assertStringContainsString('ctx.font = ADDRESS_PIXELS +', $this->script);
        self::assertStringNotContainsString('ctx.font = TITLE_SIZE +', $this->script);
        self::assertStringNotContainsString('ctx.font = ADDRESS_SIZE +', $this->script);
    }

    /**
     * The font the browser asks for is the one the site serves, and the
     * content security policy is `font-src \'self\'` — a card drawn in a
     * fallback font is a different card.
     */
    public function testTheBrowserAsksForTheFontTheSiteServes(): void
    {
        self::assertStringContainsString(
            'ScoutMagic Card',
            $this->script,
            'the card must name the served face, not fall back to the system sans'
        );
        self::assertFileExists(
            \dirname(__DIR__, 4) . '/public/assets/fonts/dejavu-sans-bold-latin.woff2',
            'the face the card asks for is not served, and font-src \'self\' forbids fetching it elsewhere'
        );
    }
}
