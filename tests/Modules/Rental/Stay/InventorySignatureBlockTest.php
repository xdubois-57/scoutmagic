<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Core\Signature\AccountSignature;
use Core\Signature\SignatureOrigin;
use Core\Signature\SignaturePng;
use Core\Signature\WitnessSignature;
use Modules\Rental\Stay\InventorySignatureBlock;
use Modules\Rental\Stay\InventorySigning;
use Modules\Rental\Stay\RenterSigning;
use PHPUnit\Framework\TestCase;

/**
 * The two frames at the foot of a signed inventory (#989, lot E): what
 * each says about how it was signed, where and when, and that the renter's
 * typed name — the one thing in it a stranger typed — is escaped.
 */
final class InventorySignatureBlockTest extends TestCase
{
    public function testTheRenterSignedOnTheSpotAndTheManagerTraced(): void
    {
        $block = new InventorySignatureBlock(
            InventorySigning::onSite(new WitnessSignature(self::png(300, 100), 'Jeanne <b>Martin</b>')),
            self::manager(SignatureOrigin::DrawnNow, 'Anne Dupont'),
            'Local Saint-Georges',
            null,
            new \DateTimeImmutable('2027-07-04 11:30:00')
        );

        $html = $block->html();

        $this->assertStringContainsString('Accepté sur place par Jeanne &lt;b&gt;Martin&lt;/b&gt;, signé au doigt', $html);
        $this->assertStringNotContainsString('<b>Martin', $html);
        $this->assertStringContainsString('Signé au doigt par Anne Dupont', $html);
        $this->assertSame(2, substr_count($html, '<img src="data:image/png;base64,'), 'both signatures are drawn');
        // A 3 : 1 drawing fills the box.
        $this->assertStringContainsString(
            'width="' . InventorySignatureBlock::IMAGE_WIDTH . '" height="' . InventorySignatureBlock::IMAGE_HEIGHT . '"',
            $html
        );
    }

    /**
     * @return iterable<string, array{RenterSigning, string}>
     */
    public static function withoutTheRenter(): iterable
    {
        yield 'absent' => [RenterSigning::ABSENT, 'Le locataire était absent'];
        yield 'refused' => [RenterSigning::REFUSED, 'Le locataire a refusé de signer'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('withoutTheRenter')]
    public function testWithoutTheRenterTheFrameSaysWhyAndTheManagerStillSigns(RenterSigning $choice, string $said): void
    {
        $html = (new InventorySignatureBlock(
            InventorySigning::without($choice),
            self::manager(SignatureOrigin::Stored, 'Anne Dupont'),
            'Local Saint-Georges',
            null,
            new \DateTimeImmutable('2027-07-04 11:30:00')
        ))->html();

        $this->assertStringContainsString($said, $html);
        $this->assertSame(1, substr_count($html, '<img '), "only the manager's signature is drawn");
        $this->assertStringContainsString('Signature enregistrée appliquée sur confirmation de Anne Dupont', $html);
    }

    /** An account without a name signs as « un gestionnaire », never as nobody. */
    public function testAnAccountWithoutANameSignsAsAManager(): void
    {
        $block = new InventorySignatureBlock(
            InventorySigning::without(RenterSigning::ABSENT),
            self::manager(SignatureOrigin::DrawnNow, null),
            'Local',
            null,
            new \DateTimeImmutable('2027-07-04 11:30:00')
        );

        $this->assertSame('Signé au doigt par un gestionnaire', $block->managerCaption());
    }

    /**
     * Where and when: the asset's name, its address on one line when it has
     * one, and the hour on the application clock — two hours past UTC in
     * July.
     */
    public function testThePlaceIsTheAssetAndItsAddressAndTheTimeBrusselsTime(): void
    {
        $utc = new \DateTimeZone('UTC');
        $withAddress = new InventorySignatureBlock(
            InventorySigning::without(RenterSigning::ABSENT),
            self::manager(SignatureOrigin::DrawnNow, 'Anne Dupont'),
            'Local Saint-Georges',
            "Rue des Scouts 12\n1000 Bruxelles",
            new \DateTimeImmutable('2027-07-13 08:05:00', $utc)
        );
        $withoutAddress = new InventorySignatureBlock(
            InventorySigning::without(RenterSigning::ABSENT),
            self::manager(SignatureOrigin::DrawnNow, 'Anne Dupont'),
            'Local Saint-Georges',
            '  ',
            new \DateTimeImmutable('2027-07-13 08:05:00', $utc)
        );

        $this->assertSame(
            'Signé sur place, Local Saint-Georges, Rue des Scouts 12, 1000 Bruxelles, le 13/07/2027 à 10:05',
            $withAddress->placeAndTime()
        );
        $this->assertSame('Signé sur place, Local Saint-Georges, le 13/07/2027 à 10:05', $withoutAddress->placeAndTime());
    }

    /** A renter on site comes with a signature, and only that way. */
    public function testOnSiteIsNeverWithoutASignature(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InventorySigning::without(RenterSigning::ON_SITE);
    }

    private static function manager(SignatureOrigin $origin, ?string $name): AccountSignature
    {
        return new AccountSignature(7, self::png(600, 200), $origin, false, $name);
    }

    private static function png(int $width, int $height): SignaturePng
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return new SignaturePng((string) ob_get_clean());
    }
}
