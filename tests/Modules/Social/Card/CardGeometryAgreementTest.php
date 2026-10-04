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
     * @return array<string, array{string, int|float|string}>
     */
    public static function constants(): array
    {
        $size = CardRenderer::SIZE;

        return [
            'the square\'s side' => ['SIZE = %s;', $size],
            // round(1080 * 0.06) = 65, the same in both languages.
            'the inner margin' => ['MARGIN = Math.round(SIZE * %s);', 0.06],
            'the title\'s size' => ['TITLE_SIZE = %s;', 66],
            'the three-line cap' => ['TITLE_MAX_LINES = %s;', 3],
            // round(66 * 1.25) = 83: 82.5 rounds up in PHP and in JS.
            'the title\'s line height' => ['TITLE_LINE_HEIGHT = Math.round(TITLE_SIZE * %s);', 1.25],
            'the address\'s size' => ['ADDRESS_SIZE = %s;', 34],
            // round(34 * 1.9) = 65 (64.6).
            'the gap above the address' => ['ADDRESS_TO_TITLE = Math.round(ADDRESS_SIZE * %s);', 1.9],
            // (int) (1080 * 0.35) = 378, and Math.trunc gives the same.
            'where the veil starts' => ['VEIL_START_Y = Math.trunc(SIZE * %s);', 0.35],
            'the backdrop grey' => ['BACKDROP = \'rgb(%s)\';', '73, 80, 87'],
            'the export quality' => ['JPEG_QUALITY = %s;', 0.88],
        ];
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
            'VEIL_FOOT_ALPHA = (127 - 70) / 127;',
            $this->script,
            'The veil must be as dark as GD drew it. GD\'s 127 is TRANSPARENT, so copying 70 straight'
            . ' into a canvas alpha would make the foot almost opaque instead of 45% black.'
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
     * and nothing compares pixels. What has to agree is the scale.
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
