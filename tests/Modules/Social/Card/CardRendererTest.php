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
        $blurred = H::sharpness($renderer->render(H::groupPhoto(), 'T', 'a.be', true, CardService::MIN_BLUR_RATIO));

        $this->assertLessThan($sharp / 4, $blurred, "sharp {$sharp}, blurred {$blurred}");
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
        $width = CardRenderer::SIZE - 2 * (int) round(CardRenderer::SIZE * (float) $class->getConstant('MARGIN'));
        $lines = $class->getMethod('wrap')->invoke(new CardRenderer(), trim($title), $size, $width);

        $this->assertIsArray($lines);
        $this->assertCount($maxLines, $lines);
        $this->assertStringEndsWith('…', (string) end($lines));
    }

    public function testSomethingThatIsNotAnImageIsRefusedInFrench(): void
    {
        $this->expectException(CardException::class);
        $this->expectExceptionMessage('Impossible de lire cette image.');

        (new CardRenderer())->render('not an image', 'T', 'a.be', true, 0.05);
    }
}
