<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Pricing;

use Modules\Rental\Pricing\BillingUnit;
use PHPUnit\Framework\TestCase;

/**
 * pricePhrase() completes a displayed unit price for a renter — "5,50 €
 * par personne et par nuit" — on the public estimate and the tracking page.
 */
class BillingUnitTest extends TestCase
{
    public function testEveryUnitContinuesAPriceSentence(): void
    {
        // The phrase follows an amount, so it must start lower-case and
        // never be empty — "5,50 € Forfait par séjour" reads as two labels
        // collided, which is exactly what this method exists to avoid.
        foreach (BillingUnit::all() as $unit) {
            $phrase = $unit->pricePhrase();

            $this->assertNotSame('', $phrase);
            $this->assertSame(
                mb_strtolower(mb_substr($phrase, 0, 1)),
                mb_substr($phrase, 0, 1),
                $unit->value . ' must continue a sentence, not start one.'
            );
        }
    }

    public function testThePersonNightPhraseSaysBoth(): void
    {
        $this->assertSame('par personne et par nuit', BillingUnit::PER_PERSON_NIGHT->pricePhrase());
    }

    public function testTheFlatStayPhraseIsNotPerAnything(): void
    {
        $this->assertSame('pour le séjour', BillingUnit::FLAT_STAY->pricePhrase());
    }
}
