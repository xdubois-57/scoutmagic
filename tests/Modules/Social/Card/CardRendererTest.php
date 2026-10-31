<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Card;

use Modules\Social\Card\CardException;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * The composed image: its shape, its blur, its text.
 */
final class CardRendererTest extends TestCase
{
    public function testTheCardIsASquareJpegOf1080(): void
    {
        $jpeg = (new CardRenderer())->render(H::groupPhoto(), 'Camp Lutins 2026', '25sv.be/galerie', false, 0.05);

        $size = getimagesizefromstring($jpeg);
        $this->assertIsArray($size);
        $this->assertSame([CardRenderer::SIZE, CardRenderer::SIZE, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function testTheCalibratedBlurTakesAwayMostOfThePhotosDetail(): void
    {
        $renderer = new CardRenderer();
        $sharp = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', false, 0.05));
        $blurred = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', true, CardService::DEFAULT_BLUR_RATIO));

        $this->assertLessThan($sharp / 4, $blurred, "sharp {$sharp}, blurred {$blurred}");
    }

    /**
     * **« Net » means net** (issue #706, IT-02).
     *
     * The floor is gone and the slider reaches zero, so a ratio of zero
     * has to leave the photo alone. It did not: the radius was clamped up
     * with `max(1.0, …)`, so zero fell through to the three Gaussian
     * passes and cost a 1.4× loss of detail — measured, not supposed. It
     * was invisible while the ratio could never go below 0.05, and it
     * would have put the fallback preview at odds with the card the
     * browser draws, over the one thing the slider promises.
     */
    public function testARatioOfZeroLeavesThePhotoExactlyAsItWas(): void
    {
        $renderer = new CardRenderer();
        $sharp = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', false, 0.0));
        $asked = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', true, 0.0));

        $this->assertSame($sharp, $asked, "sharp {$sharp}, asked-for-sharp {$asked}");
    }

    /**
     * And a radius that rounds to less than one pixel is the same thing:
     * there is no blur to apply, so none is applied. The slider's own
     * step never lands here, but a value typed into the database can.
     */
    public function testASubPixelRadiusIsNoBlurAtAll(): void
    {
        $renderer = new CardRenderer();
        $sharp = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', false, 0.0));
        // 0.0005 of 1080 is 0.54 px.
        $asked = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', true, 0.0005));

        $this->assertSame($sharp, $asked);
    }

    public function testTheBlurIsAShareOfTheSideNotAPixelCount(): void
    {
        // The same photo at two resolutions ends up equally veiled.
        $photo = imagecreatefromstring(H::groupPhoto());
        $this->assertNotFalse($photo);
        $small = imagescale($photo, 600);
        $this->assertNotFalse($small);
        ob_start();
        imagejpeg($small, null, 92);
        $smallJpeg = (string) ob_get_clean();

        $renderer = new CardRenderer();
        $large = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', true, 0.05));
        $reduced = H::sharpness($renderer->render($smallJpeg, 'T', 'a.be', true, 0.05));

        $this->assertEqualsWithDelta($large, $reduced, max($large, $reduced) * 0.5);
    }

    public function testALongTitleIsCutRatherThanOverflowing(): void
    {
        $title = str_repeat('Grand jeu de nuit dans les bois de la Hulpe ', 6);

        $jpeg = (new CardRenderer())->render(H::groupPhoto(), $title, '25sv.be', true, 0.05);
        $this->assertNotFalse(imagecreatefromstring($jpeg));

        // What writeText() lays out: three lines at most, the last one cut.
        $class = new \ReflectionClass(CardRenderer::class);
        $maxLines = (int) $class->getConstant('TITLE_MAX_LINES');
        $size = (int) $class->getConstant('TITLE_SIZE');
        $width = (int) $class->getConstant('MAX_TEXT_WIDTH');
        $lines = $class->getMethod('wrap')->invoke(new CardRenderer(), trim($title), $size, $width);

        $this->assertIsArray($lines);
        $this->assertCount($maxLines, $lines);
        $this->assertStringEndsWith('…', (string) end($lines));
    }

    // ————— The text block, where it is drawn (issue #929) —————

    /** A flat, dark photo: nothing but the text can then be near white. */
    private static function flatPhoto(int $grey = 40): string
    {
        $image = imagecreatetruecolor(1200, 1200);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $grey, $grey, $grey));
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /**
     * The box around everything drawn in near-white, read from the pixels
     * of the finished card rather than computed from the layout's own
     * numbers — a layout can agree with itself and still be off the page.
     *
     * @return array{minX: int, maxX: int, minY: int, maxY: int}
     */
    private static function textBounds(string $jpeg): array
    {
        $card = imagecreatefromstring($jpeg);
        self::assertNotFalse($card);
        $bounds = ['minX' => PHP_INT_MAX, 'maxX' => -1, 'minY' => PHP_INT_MAX, 'maxY' => -1];
        for ($y = 0; $y < CardRenderer::SIZE; $y++) {
            for ($x = 0; $x < CardRenderer::SIZE; $x++) {
                $rgb = imagecolorat($card, $x, $y);
                if (($rgb >> 16 & 0xFF) > 200 && ($rgb >> 8 & 0xFF) > 200 && ($rgb & 0xFF) > 200) {
                    $bounds['minX'] = min($bounds['minX'], $x);
                    $bounds['maxX'] = max($bounds['maxX'], $x);
                    $bounds['minY'] = min($bounds['minY'], $y);
                    $bounds['maxY'] = max($bounds['maxY'], $y);
                }
            }
        }
        imagedestroy($card);
        self::assertGreaterThan(-1, $bounds['maxX'], 'no text was drawn at all');

        return $bounds;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function titles(): array
    {
        return [
            'one line' => ['Camp 2026', 'unite.example.be'],
            'two lines' => ['Week-end de rentrée des Lutins', 'unite.example.be'],
            'three lines' => ['Grand jeu de nuit dans les bois de la Hulpe', 'unite.example.be'],
            'cut with an ellipsis' => [str_repeat('Grand jeu de nuit dans les bois de la Hulpe ', 6), 'unite.example.be'],
            'one word wider than the line' => ['Supercalifragilisticexpialidocious', 'unite.example.be'],
            'a long address' => ['Camp 2026', 'une-adresse-vraiment-tres-longue-pour-une-unite-de-scouts.example.be'],
        ];
    }

    /**
     * **Every glyph of the block sits inside the zone every common crop
     * keeps** (issue #929): columns 135..945, rows 257..823. A margin of
     * twenty pixels is required on top of it, for overshoot, the veil and a
     * crop that is a little different tomorrow.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('titles')]
    public function testTheWholeTextBlockSitsInsideTheCommonZone(string $title, string $address): void
    {
        $bounds = self::textBounds((new CardRenderer())->render(self::flatPhoto(), $title, $address, false, 0.0));

        self::assertGreaterThanOrEqual(135 + 20, $bounds['minX'], 'text reaches past the zone\'s left edge');
        self::assertLessThanOrEqual(945 - 20, $bounds['maxX'], 'text reaches past the zone\'s right edge');
        self::assertGreaterThanOrEqual(257 + 20, $bounds['minY'], 'text reaches above the zone');
        self::assertLessThanOrEqual(823 - 20, $bounds['maxY'], 'text reaches below the zone');
    }

    /**
     * The three reference crops, each centred and in « cover » mode, and the
     * text complete in every one: a thread's 4:3 window, Instagram's 3:4
     * profile tile, a 1.91:1 Facebook preview. Scenarios, not guarantees —
     * what a platform does next year is not in this file.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('titles')]
    public function testNoLineIsCutInTheThreeReferenceCrops(string $title, string $address): void
    {
        $bounds = self::textBounds((new CardRenderer())->render(self::flatPhoto(), $title, $address, false, 0.0));
        $crops = [
            '4:3' => ['x' => [0, 1080], 'y' => [135, 945]],
            '3:4' => ['x' => [135, 945], 'y' => [0, 1080]],
            '1.91:1' => ['x' => [0, 1080], 'y' => [257, 823]],
        ];

        foreach ($crops as $name => $crop) {
            self::assertGreaterThanOrEqual($crop['x'][0], $bounds['minX'], "left of the {$name} crop");
            self::assertLessThanOrEqual($crop['x'][1], $bounds['maxX'], "right of the {$name} crop");
            self::assertGreaterThanOrEqual($crop['y'][0], $bounds['minY'], "top of the {$name} crop");
            self::assertLessThanOrEqual($crop['y'][1], $bounds['maxY'], "foot of the {$name} crop");
        }
    }

    public function testTheBlockIsCentredOnTheCardsVerticalAxis(): void
    {
        $bounds = self::textBounds(
            (new CardRenderer())->render(self::flatPhoto(), 'Week-end de rentrée des Lutins', 'a.be', false, 0.0)
        );

        self::assertEqualsWithDelta(540, ($bounds['minX'] + $bounds['maxX']) / 2, 12);
    }

    /**
     * The address stays where it is whatever the title does, and a longer
     * title grows upwards — into the zone's spare height, not towards its foot.
     */
    public function testALongerTitleGrowsUpwardsAndTheAddressStaysPut(): void
    {
        $renderer = new CardRenderer();
        $short = self::textBounds($renderer->render(self::flatPhoto(), 'Camp', 'a.be', false, 0.0));
        $long = self::textBounds($renderer->render(self::flatPhoto(), 'Grand jeu de nuit dans les bois de la Hulpe', 'a.be', false, 0.0));

        self::assertSame($short['maxY'], $long['maxY']);
        self::assertLessThan($short['minY'], $long['minY']);
    }

    /**
     * The veil is a glow hugging the text, not a stripe across the photo:
     * far from the block the photo is left exactly as it was.
     */
    public function testTheVeilLeavesTheRestOfThePhotoAlone(): void
    {
        $jpeg = (new CardRenderer())->render(self::flatPhoto(80), 'Camp 2026', 'a.be', false, 0.0);
        $card = imagecreatefromstring($jpeg);
        self::assertNotFalse($card);
        $grey = static fn (int $x, int $y): int => (int) (imagecolorat($card, $x, $y) & 0xFF);

        self::assertEqualsWithDelta(80, $grey(8, 40), 3, 'the top corner of the photo was darkened');
        self::assertEqualsWithDelta(80, $grey(540, 200), 3, 'far above the block was darkened');
        self::assertEqualsWithDelta(80, $grey(8, 700), 3, 'the side of the photo, level with the block, was darkened');
        self::assertEqualsWithDelta(80, $grey(1070, 1070), 3, 'the foot corner of the photo was darkened');
        // Level with the title, left of its first letter, inside the glow's full part:
        // 80 × (70 / 127) is about 44, and GD's interpolation and 7-bit alpha move it a few levels.
        self::assertGreaterThan(36, $grey(300, 700), 'the glow is darker than it should be');
        self::assertLessThan(52, $grey(300, 700), 'the glow is not as dark as it should be');
        imagedestroy($card);
    }

    /**
     * A short title gets a short glow: the veil follows the block, so a
     * one-line title does not dim the rows a three-line one needs.
     */
    public function testTheGlowFollowsTheBlockSoAShortTitleDimsLess(): void
    {
        $renderer = new CardRenderer();
        $dimmed = static function (string $title) use ($renderer): int {
            $card = imagecreatefromstring($renderer->render(self::flatPhoto(80), $title, 'a.be', false, 0.0));
            self::assertNotFalse($card);
            $count = 0;
            for ($y = 0; $y < CardRenderer::SIZE; $y += 4) {
                if ((imagecolorat($card, 8, $y) & 0xFF) < 78 || (imagecolorat($card, 540, $y) & 0xFF) < 78) {
                    $count++;
                }
            }
            imagedestroy($card);

            return $count;
        };

        self::assertLessThan(
            $dimmed('Grand jeu de nuit dans les bois de la Hulpe'),
            $dimmed('Camp'),
            'a one-line title dims as many rows as a three-line one'
        );
    }

    /**
     * The same function in both languages: these sample points, and the
     * strengths expected there, are asserted word for word in
     * `tests/js/social-card.test.js`.
     */
    public function testTheVeilsStrengthIsTheOneTheBrowserComputes(): void
    {
        $box = ['left' => 300.0, 'top' => 520.0, 'right' => 780.0, 'bottom' => 790.0];

        foreach (self::veilSamples() as [$x, $y, $expected]) {
            self::assertEqualsWithDelta($expected, CardRenderer::veilStrength($x, $y, $box), 1e-9, "at {$x},{$y}");
        }
    }

    /**
     * @return list<array{int, int, float}>
     */
    public static function veilSamples(): array
    {
        return [
            [540, 650, 1.0],
            [290, 650, 1.0],
            [260, 650, 0.95703125],
            [200, 650, 0.5],
            [100, 650, 0.0],
            [540, 400, 0.31640625],
            [540, 300, 0.0],
            [880, 890, 0.2071067812],
            [800, 810, 1.0],
            [0, 0, 0.0],
        ];
    }

    public function testSomethingThatIsNotAnImageIsRefusedInFrench(): void
    {
        $this->expectException(CardException::class);
        $this->expectExceptionMessage('Impossible de lire cette image.');

        (new CardRenderer())->render('not an image', 'T', 'a.be', true, 0.05);
    }
}
