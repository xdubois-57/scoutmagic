<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Card;

use Modules\Social\Card\CardException;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\ReceivedCard;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * What the server accepts as « the card the browser drew » (issue #706,
 * IT-02).
 *
 * The server stopped composing and started checking, so this is the whole
 * of what it checks. Each refusal carries a French sentence, because it
 * reaches a chief who is about to publish and « Erreur » would leave them
 * with nothing to do.
 */
final class ReceivedCardTest extends TestCase
{
    /** A real card, composed the way the server used to compose one. */
    private static function card(): string
    {
        return (new CardRenderer())->render(H::groupPhoto(), 'Week-end', 'unite.be', true, 0.025);
    }

    public function testARealCardIsAccepted(): void
    {
        $card = self::card();

        ReceivedCard::assertUsable($card);
        $this->assertTrue(ReceivedCard::isUsable($card));
    }

    public function testNothingAtAllIsRefusedWithSomethingToDo(): void
    {
        $this->expectException(CardException::class);
        $this->expectExceptionMessage('n\'est pas arrivée');

        ReceivedCard::assertUsable('');
    }

    public function testSomethingThatIsNotAnImageIsRefused(): void
    {
        $this->expectException(CardException::class);
        $this->expectExceptionMessage('JPEG');

        ReceivedCard::assertUsable('<!DOCTYPE html><p>pas une image</p>');
    }

    /**
     * A PNG is a real image and still refused: the card is a photograph,
     * and a PNG of one is several times the weight for no gain. One
     * format arrives here because one format is drawn.
     */
    public function testAnotherImageFormatIsRefusedEvenThoughItIsAnImage(): void
    {
        $square = imagecreatetruecolor(CardRenderer::SIZE, CardRenderer::SIZE);
        ob_start();
        imagepng($square);
        $png = (string) ob_get_clean();
        imagedestroy($square);

        $this->assertNotSame('', $png);
        $this->expectException(CardException::class);
        $this->expectExceptionMessage('JPEG');

        ReceivedCard::assertUsable($png);
    }

    /**
     * **The dimensions are exact, not a minimum.** Every card already
     * published is a 1080 square, and that is what lets the composer
     * promise « exactly what will be published ». A bigger one is not a
     * better card, it is a different format.
     */
    public function testAJpegOfTheWrongSizeIsRefused(): void
    {
        foreach ([[1080, 1079], [1079, 1080], [2160, 2160], [600, 600]] as [$width, $height]) {
            $image = imagecreatetruecolor($width, $height);
            ob_start();
            imagejpeg($image, null, 88);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            $this->assertFalse(
                ReceivedCard::isUsable($jpeg),
                "{$width}×{$height} was accepted as a card"
            );
        }
    }

    public function testTheRefusalForAWrongSizeNamesTheSizeItWants(): void
    {
        $image = imagecreatetruecolor(600, 600);
        ob_start();
        imagejpeg($image, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $this->expectException(CardException::class);
        $this->expectExceptionMessage('1080 pixels de côté');

        ReceivedCard::assertUsable($jpeg);
    }

    /**
     * The weight is a ceiling. A card at quality 0.88 lands far below it,
     * so anything past it is a sign that something else arrived.
     */
    public function testSomethingTooHeavyIsRefusedBeforeItIsDecoded(): void
    {
        $this->expectException(CardException::class);
        $this->expectExceptionMessage('dépasse');

        ReceivedCard::assertUsable(str_repeat('x', ReceivedCard::MAX_BYTES + 1));
    }

    /**
     * And a real card is nowhere near the ceiling — the point of stating
     * the measurement rather than trusting the number.
     */
    public function testARealCardIsFarUnderTheCeiling(): void
    {
        $bytes = strlen(self::card());

        $this->assertLessThan(
            ReceivedCard::MAX_BYTES / 2,
            $bytes,
            "a composed card weighs {$bytes} bytes, which is close to the ceiling — one of the two is wrong"
        );
    }
}
