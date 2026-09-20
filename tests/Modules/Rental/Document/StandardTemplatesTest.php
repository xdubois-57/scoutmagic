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
 * The three bodies ScoutMagic ships as a starting point.
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

    // ── The conditions (§22.5) ──────────────────────────────────────────

    /**
     * The odd one out: not a DocumentType, and read by a visitor who has no
     * booking yet — so there is nothing to substitute into it. A stray
     * `{{ … }}` would therefore reach a renter as literal braces with
     * nothing to replace them, which is worse here than in a contract.
     */
    public function testTheConditionsCarryNoPlaceholderAtAll(): void
    {
        $this->assertStringNotContainsString('{{', StandardTemplates::conditions());
    }

    public function testTheConditionsSayTheThingsALettingHasToSay(): void
    {
        // Not a wording test — the text is meant to be edited. These are the
        // subjects a renter has to have been told about before ticking a box
        // that the site then hashes as proof.
        $conditions = StandardTemplates::conditions();

        foreach ([
            'acompte',
            'caution',
            'annulation',
            'état des lieux',
            'assurance',
            'capacité maximale',
            'droit belge',
        ] as $subject) {
            $this->assertStringContainsString(
                $subject,
                mb_strtolower($conditions),
                'The shipped conditions must cover: ' . $subject
            );
        }
    }

    public function testTheConditionsSurviveTheSanitizerUnchanged(): void
    {
        // They are stored and re-rendered through Core\Security\HtmlSanitizer
        // like any other rich text. A tag it strips would mean the shipped
        // body and the stored one differ, and « réinitialiser » would never
        // read as standard again.
        $conditions = StandardTemplates::conditions();
        $sanitized = (new \Core\Security\HtmlSanitizer())->sanitize($conditions);

        $strip = static fn(string $html): string => (string) preg_replace('/\s+/u', '', $html);

        $this->assertSame($strip($conditions), $strip($sanitized));
    }

    /**
     * **The shipped text is the binding one, so it may not promise more
     * than the site does.** A booking keeps `conditions_version` and the
     * SHA-256 of the text it accepted — never the text itself
     * (`RentalBookingService::hashAcceptedText()`), and `editable_contents`
     * has no revision history, so the exact prior wording is unrecoverable
     * the moment a unit edits its conditions. Saying otherwise to a renter
     * is a promise nobody can keep.
     */
    public function testTheConditionsPromiseAnImprintAndNotACopyOfThemselves(): void
    {
        $conditions = StandardTemplates::conditions();

        $this->assertStringContainsString('une empreinte', $conditions);
        $this->assertStringContainsString('pas une copie du texte', $conditions);
        $this->assertStringNotContainsString('le texte accepté est conservé', $conditions);
    }
}
