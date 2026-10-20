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

        foreach (['{{ prix_total }}', '{{ caution }}', '{{ communication }}', '{{ capacite_personnes }}'] as $money) {
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

    // ── Annex 8.1 of the Walloon tourism code (issue #977) ─────────────

    /**
     * The contract text, whitespace collapsed and tags removed — the way a
     * reader of the PDF reads it, whatever the line breaks of the source.
     */
    private static function readableContract(): string
    {
        $text = html_entity_decode(strip_tags(StandardTemplates::contract()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    /**
     * Point 9 of the annex has an IMPOSED wording: the old clause — « il
     * rembourse l'intégralité des sommes versées, sans autre indemnité » —
     * contradicted it. Word for word, every sentence of it.
     */
    public function testArticleNineReproducesTheAnnexWordingWordForWord(): void
    {
        $contract = self::readableContract();

        foreach ([
            "Au cas où le bailleur manquerait à son obligation de délivrance, il sera redevable envers le "
                . "locataire, outre le remboursement de l'acompte éventuellement perçu, d'une indemnité minimale "
                . 'irréductible équivalent à :',
            "cinquante pour cent du prix de la location s'il en a informé le locataire au minimum quatre mois "
                . 'avant la prise de cours de la location ;',
            'cent pour cent du prix de la location dans les autres cas ;',
            "Si le locataire justifie d'un préjudice supérieur, le bailleur sera tenu de l'indemniser "
                . 'intégralement.',
            "Il en va de même pour le locataire s'il ne respecte pas ses obligations.",
        ] as $sentence) {
            $this->assertStringContainsString($sentence, $contract);
        }
        $this->assertStringNotContainsString('sans autre indemnité', $contract);
    }

    /** The renter's withdrawal: a progressive scale from the free-withdrawal deadline, not « tout est dû ». */
    public function testTheRentersWithdrawalIsAProgressiveScaleFromTheFreeDeadline(): void
    {
        $contract = self::readableContract();

        $this->assertStringContainsString('{{ date_limite_desistement }} inclus, il le fait sans indemnité', $contract);
        foreach (['vingt-cinq pour cent', 'cinquante pour cent', 'soixante-quinze pour cent'] as $step) {
            $this->assertStringContainsString($step, $contract);
        }
        $this->assertStringNotContainsString("L'acompte reste acquis au bailleur", $contract);
        $this->assertStringNotContainsString('le solde reste dû', $contract);
    }

    /** Article 8: no exclusion of bodily harm, nor of the landlord's own fault. */
    public function testArticleEightExcludesOnlyPersonalBelongingsAndNeverTheLandlordsFault(): void
    {
        $contract = self::readableContract();

        $this->assertStringContainsString(
            "Le bailleur n'est pas responsable des vols, des pertes ni des dommages aux biens personnels des "
                . 'occupants, sauf faute de sa part.',
            $contract
        );
        $this->assertStringNotContainsString('accident', $contract);
    }

    /** Article 11: the courts where the asset is, without taking a private renter's own judge away. */
    public function testArticleElevenKeepsTheCourtsOfThePlaceAndThePrivateRentersJudge(): void
    {
        $contract = self::readableContract();

        $this->assertStringContainsString('les tribunaux du lieu où se trouve le bien loué sont compétents', $contract);
        $this->assertStringContainsString('juge de son domicile', $contract);
        $this->assertStringNotContainsString('seuls compétents', $contract);
    }

    /** Article 10 names the controller, the purpose, the duration, the rights and where to write. */
    public function testArticleTenNamesTheControllerTheDurationAndTheRights(): void
    {
        $contract = self::readableContract();

        foreach ([
            'Le bailleur, {{ nom_bailleur }}, est responsable du traitement',
            'servent uniquement à la gestion de cette location',
            'conservées {{ duree_conservation }}',
            'accéder à ses données, les faire rectifier ou en demander l\'effacement',
            'en écrivant à {{ contact_donnees }}',
        ] as $part) {
            $this->assertStringContainsString($part, $contract);
        }
    }

    public function testTheContractStatesEveryElementTheAnnexLists(): void
    {
        $contract = StandardTemplates::contract();

        foreach ([
            '{{ adresse_bailleur }}',        // 1° the landlord
            '{{ locataire_adresse }}',       // 2° the renter…
            '{{ responsable_nom }}',         // …and the group's responsible person
            '{{ responsable_telephone }}',
            '{{ heure_arrivee }}',           // 3° the period, with its hours
            '{{ bien_adresse }}',            // 4° the place, and its address
            '{{ acompte }}',                 // 8° a. the deposit
            '{{ iban }}',                    //    b. the account
            '{{ titulaire_compte }}',
            '{{ date_limite_desistement }}', //    c. the free-withdrawal deadline
            '{{ echeance_solde }}',          //    d. the day of the balance
            '{{ echeance_acompte }}',
        ] as $keyword) {
            $this->assertStringContainsString($keyword, $contract);
        }
        // 11° where and when each party signs.
        $this->assertSame(2, substr_count($contract, 'Fait à '));
    }

    /** « nuit(s) » and its kind are agreed keywords now, and the header prints no empty enterprise number. */
    public function testTheContractAgreesItsCountsAndPrintsNoEmptyEnterpriseNumber(): void
    {
        $contract = StandardTemplates::contract();

        $this->assertStringNotContainsString('(s)', $contract);
        $this->assertStringNotContainsString('bon père de famille', $contract);
        $this->assertStringContainsString('en personne prudente et raisonnable', self::readableContract());
        $this->assertStringNotContainsString("N° d'entreprise", $contract);
        $this->assertStringContainsString('{{ nom_bailleur }}{{ mention_bce_bailleur }}', $contract);
    }

    /** Stored and rendered through the sanitizer: what it strips would never reach the PDF. */
    public function testTheContractSurvivesTheSanitizerUnchanged(): void
    {
        $contract = StandardTemplates::contract();
        $sanitized = (new \Core\Security\HtmlSanitizer())->sanitize($contract);
        // `&nbsp;` comes back as the character itself, which is the same text.
        $strip = static fn(string $html): string => (string) preg_replace(
            '/\s+/u',
            '',
            str_replace(['&nbsp;', "\u{00A0}"], '', $html)
        );

        $this->assertSame($strip($contract), $strip($sanitized));
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
