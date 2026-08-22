<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\DocumentKeywords;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\StandardTemplates;
use PHPUnit\Framework\TestCase;

/**
 * The two bodies ScoutMagic ships as a starting point.
 *
 * The assertion that matters most is the first one: a keyword renamed in
 * DocumentKeywords must never leave these two shipping with literal braces
 * in them, which is the exact failure the closed list exists to prevent for
 * a unit's own templates.
 */
class StandardTemplatesTest extends TestCase
{
    public function testEveryPlaceholderTheyUseIsOnTheClosedList(): void
    {
        foreach ([StandardTemplates::contract(), StandardTemplates::invoice()] as $body) {
            $this->assertSame(
                [],
                DocumentKeywords::unknownIn($body),
                'A shipped template must never use a keyword nothing substitutes.'
            );
        }
    }

    public function testTheContractSaysTheThingsALettingHasToSay(): void
    {
        // Not a wording test — the text is meant to be edited. These are the
        // clauses a unit letting a hall cannot do without, and losing one of
        // them in a reword is worth catching.
        $contract = StandardTemplates::contract();

        foreach (['{{ prix_total }}', '{{ caution }}', '{{ communication }}', '{{ capacite }}'] as $money) {
            $this->assertStringContainsString($money, $contract);
        }

        // The clause list follows the Atouts Camps model contract for a
        // Belgian camp location: charges at cost on contradictory meter
        // readings, and disputes before the courts of where the asset is.
        foreach ([
            'État des lieux',
            'Assurance',
            'Annulation',
            'Garantie',
            'Charges et consommations',
            'Litiges',
            'capacité maximale',
        ] as $clause) {
            $this->assertStringContainsString($clause, $contract);
        }
    }

    public function testTheInvoiceCarriesTheVatSentenceAndNeverAVatCalculation(): void
    {
        // §6.27: no VAT is ever computed. What a Belgian invoice with no VAT
        // on it needs is the sentence saying why, which is the asset's own.
        $invoice = StandardTemplates::invoice();

        $this->assertStringContainsString('{{ mention_tva }}', $invoice);
        $this->assertStringNotContainsString('21', $invoice);
        $this->assertStringNotContainsString('TVA incluse', $invoice);
    }

    public function testTheInvoiceKeepsTheGuaranteeOutOfThePrice(): void
    {
        // A unit holding 500 € of somebody else's money has not earned it.
        $this->assertStringContainsString(
            'ne fait pas partie du prix',
            StandardTemplates::invoice()
        );
    }

    public function testOnlyTheTwoGeneratedTypesHaveAStandardBody(): void
    {
        foreach (DocumentType::cases() as $type) {
            $standard = StandardTemplates::forType($type);

            if ($type->isGenerated()) {
                $this->assertNotNull($standard, $type->value . ' is generated from a template.');
                $this->assertNotSame('', trim((string) $standard));
            } else {
                $this->assertNull($standard, $type->value . ' is uploaded, not generated.');
            }
        }
    }
}
