<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\ContractFingerprint;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use PHPUnit\Framework\TestCase;

/**
 * What a contract says about its booking, hashed (#708, IT-20): what it
 * states counts, and nothing else.
 */
class ContractFingerprintTest extends TestCase
{
    /** @return array<string, string|null> */
    private static function values(array $overrides = []): array
    {
        return $overrides + [
            'reference' => 'LOC-2027-0042',
            'bien' => 'Local Saint-Georges',
            'date_arrivee' => '01/07/2027',
            'date_depart' => '04/07/2027',
            'nuits' => '3',
            'participants' => '20',
            'prix_total' => '360,00 €',
            'locataire_nom' => 'Jeanne Martin',
            'locataire_email' => 'jeanne@example.be',
            'date_du_jour' => '03/10/2026',
            'communication' => '+++100/0000/00034+++',
        ];
    }

    private static function price(int $amountCents): PriceQuote
    {
        return new PriceQuote(
            lines: [new PriceLine('Séjour', 3, intdiv($amountCents, 3), $amountCents, 'base')],
            totalCents: $amountCents,
            nights: 3,
            persons: 20,
            quantity: 3,
            billingUnit: BillingUnit::PER_NIGHT
        );
    }

    public function testTheSameBookingHasTheSameFingerprint(): void
    {
        $this->assertSame(
            ContractFingerprint::of(self::values(), self::price(36000)),
            ContractFingerprint::of(self::values(), self::price(36000))
        );
    }

    /** The day the PDF was made is not something the booking says. */
    public function testTheDayOfGenerationAndTheCommunicationDoNotCount(): void
    {
        $this->assertSame(
            ContractFingerprint::of(self::values(), self::price(36000)),
            ContractFingerprint::of(self::values(['date_du_jour' => '04/10/2026', 'communication' => null]), self::price(36000))
        );
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function changes(): array
    {
        return [
            'les dates' => [['date_arrivee' => '02/07/2027']],
            'les participants' => [['participants' => '25']],
            'le prix' => [['prix_total' => '400,00 €']],
            'le locataire' => [['locataire_email' => 'autre@example.be']],
            'son adresse' => [['locataire_adresse' => 'Rue du Moulin 3, 5000 Namur']],
        ];
    }

    /**
     * @param array<string, string|null> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testWhatTheContractStatesChangesIt(array $change): void
    {
        $this->assertNotSame(
            ContractFingerprint::of(self::values(), self::price(36000)),
            ContractFingerprint::of(self::values($change), self::price(36000))
        );
    }

    /**
     * What follows the asset's settings — its name, its hours, its deposit
     * rate and security deposit — is not the booking changing.
     */
    public function testTheAssetsOwnSettingsDoNotCount(): void
    {
        $this->assertSame(
            ContractFingerprint::of(self::values(), self::price(36000)),
            ContractFingerprint::of(self::values([
                'bien' => 'Grande salle',
                'heure_arrivee' => '17:00',
                'heure_depart' => '10:00',
                'acompte' => '100,00 €',
                'caution' => '300,00 €',
            ]), self::price(36000))
        );
    }

    /**
     * A detail the contract left blank, filled in since, voids nothing; a
     * value it printed, changed, still does.
     */
    public function testFillingABlankIsNotAChangeButCorrectingAValueIs(): void
    {
        $generated = self::values(['locataire_adresse' => null]);
        $fingerprint = ContractFingerprint::of($generated, self::price(36000));
        $filled = self::values(['locataire_adresse' => 'Rue du Moulin 3, 5000 Namur']);

        $this->assertSame($fingerprint, ContractFingerprint::against($filled, $generated, self::price(36000)));

        $printed = self::values(['locataire_adresse' => 'Rue du Moulin 3, 5000 Namur']);
        $this->assertNotSame(
            ContractFingerprint::of($printed, self::price(36000)),
            ContractFingerprint::against(
                self::values(['locataire_adresse' => 'Place Saint-Aubain 1, 5000 Namur']),
                $printed,
                self::price(36000)
            )
        );
    }

    /** A line of the price changes it even where the total does not show it. */
    public function testAPriceLineChangesIt(): void
    {
        $this->assertNotSame(
            ContractFingerprint::of(self::values(), self::price(36000)),
            ContractFingerprint::of(self::values(), self::price(36300))
        );
    }
}
