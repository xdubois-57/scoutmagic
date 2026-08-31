<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Modules\Finance\Service\StructuredCommunicationService;

/**
 * The unit's bank accounts and what moves through them, declared as data.
 *
 * `bnp` is the only format ScoutMagic reads
 * (BankStatementParserFactory::getSupportedBankCodes()), so there is one
 * shape of file to produce and no choice to make.
 *
 * **A finance "exercice" is a scout year**, not a module-owned entity:
 * FiscalYearRepository::findForDate() resolves a line's exercise straight out
 * of `scout_years`, and ImportService refuses a line no exercise covers. Every
 * date below therefore has to sit inside one of UnitBlueprint::YEARS, which
 * run 1 September to 31 August.
 *
 * **The IBANs are fictional but checksum-valid**, and that is a deliberate
 * departure from `tests/fixtures/finance/bnp_statement_sample.csv`, whose
 * `BE00 0000 0000 000X` fails the mod-97 check. The builder creates its
 * accounts through the real Modules\Finance\Service\FinanceService::
 * createAccount(), which validates the IBAN (IbanNormalizer::isValidFullIban()) —
 * an invalid one would have to bypass the service layer, which is the one thing
 * this dataset exists not to do. The bank code `000` is not allocated to any
 * Belgian institution, so these belong to nobody; only the check digits differ
 * from the sample's.
 */
final class BankBlueprint
{
    public const DIRECTORY = 'bank';

    /** The only bank format the application parses. */
    public const BANK_CODE = 'bnp';

    /**
     * @var array<string, array{name: string, iban: string, opening: float, roleMinView: string}>
     */
    public const ACCOUNTS = [
        'unite' => [
            'name' => "Compte d'unité",
            'iban' => 'BE27 0000 0000 0001',
            'opening' => 4250.00,
            'roleMinView' => 'intendant',
        ],
        'camps' => [
            'name' => 'Compte camps',
            'iban' => 'BE97 0000 0000 0002',
            'opening' => 1875.00,
            'roleMinView' => 'intendant',
        ],
    ];

    /**
     * Ten-digit bases turned into Belgian structured communications by
     * Modules\Finance\Service\StructuredCommunicationService::format() — the
     * real generator's own public static formatter, so the mod-97 check digits
     * are right by construction rather than by hand.
     *
     * Declared here rather than drawn, because the builder creates matching
     * expected receivables from this same list in IT-06: the payment on the
     * statement and the receivable it settles have to carry the same string or
     * the "Paiements attendus" page reconciles nothing.
     *
     * @var array<string, list<string>> scout year label => 10-digit bases
     */
    public const COTISATION_BASES = [
        '2024-2025' => ['1240010001', '1240010002', '1240010003', '1240010004', '1240010005', '1240010006'],
        '2025-2026' => ['1250010001', '1250010002', '1250010003', '1250010004', '1250010005'],
        '2026-2027' => ['1260010001', '1260010002', '1260010003', '1260010004', '1260010005', '1260010006'],
    ];

    /**
     * Recurring movements, one entry per label the unit actually sees in a
     * year. The `rule` column is not read by anything — it records which
     * pattern of FinanceService::DEFAULT_CATEGORY_RULE_PATTERNS the label is
     * meant to trip, so that a rule renamed or tightened one day can be
     * checked against this table instead of against a wall of CSV.
     *
     * @var list<array{account: string, label: string, min: int, max: int, sign: int, rule: string}>
     */
    public const RECURRING = [
        ['account' => 'unite', 'label' => 'Cotisations fédérales — quadrimestre', 'min' => -2400, 'max' => -1800, 'sign' => -1, 'rule' => 'Cotisations'],
        ['account' => 'unite', 'label' => 'Vente de calendriers', 'min' => 400, 'max' => 900, 'sign' => 1, 'rule' => 'Calendriers'],
        ['account' => 'unite', 'label' => 'Subside communal jeunesse', 'min' => 600, 'max' => 1400, 'sign' => 1, 'rule' => 'Subsides'],
        ['account' => 'unite', 'label' => 'Achat materiel de section', 'min' => -420, 'max' => -90, 'sign' => -1, 'rule' => 'Matériel'],
        ['account' => 'unite', 'label' => 'Pharmacie — recharge trousses', 'min' => -180, 'max' => -60, 'sign' => -1, 'rule' => 'Matériel'],
        ['account' => 'unite', 'label' => 'Formation animateurs — inscriptions', 'min' => -640, 'max' => -220, 'sign' => -1, 'rule' => 'Formations'],
        ['account' => 'unite', 'label' => "Fete d'unite — bar et petite restauration", 'min' => 700, 'max' => 1900, 'sign' => 1, 'rule' => "Fête d'unité"],
        ['account' => 'unite', 'label' => "Temps d'unite — location de salle", 'min' => -320, 'max' => -140, 'sign' => -1, 'rule' => "Temps d'Unité (TU)"],
        ['account' => 'unite', 'label' => 'Grande journee — transport', 'min' => -480, 'max' => -200, 'sign' => -1, 'rule' => 'Grande journée'],
        ['account' => 'camps', 'label' => 'Camp ete — acompte intendance', 'min' => -2600, 'max' => -1400, 'sign' => -1, 'rule' => 'Camp été'],
        ['account' => 'camps', 'label' => 'Camp ete — participation des parents', 'min' => 900, 'max' => 2400, 'sign' => 1, 'rule' => 'Camp été'],
        ['account' => 'camps', 'label' => 'Weekend de section — hebergement', 'min' => -900, 'max' => -320, 'sign' => -1, 'rule' => 'Weekend de section'],
    ];

    /**
     * Counterparty names. Fictional people and plausible-but-invented
     * suppliers; nothing here is a real business.
     *
     * @var non-empty-list<string>
     */
    public const COUNTERPARTIES = [
        'Les Scouts ASBL', 'Commune de Genappe', 'Fournitures Bivouac SPRL',
        'Ferme du Grand Pré', 'Autocars Delvoye', 'Imprimerie du Sart',
        'Centre de formation Ourthe', 'Pharmacie du Village', 'Gîte de la Sapinière',
    ];

    /** How many recurring movements each account gets per year, on top of the special lines. */
    public const RECURRING_PER_YEAR = ['unite' => 22, 'camps' => 9];

    /**
     * How many lines of a year's file are repeated at the head of the next
     * year's file, carrying the same REFERENCE BANQUE.
     *
     * This is what a real download does when somebody asks for "the last
     * fifteen months" — and it is the only way to exercise the deduplication
     * ImportService performs on that reference. The repeated lines are dated
     * in the earlier exercise, which is where FiscalYearRepository::
     * findForDate() puts them regardless of which file they arrived in.
     */
    public const OVERLAP_LINES = 3;

    /**
     * The first BBAN handed to a section account. Section IBANs are computed
     * rather than listed: there is one per section, the sections are declared
     * next door in UnitBlueprint, and a second list to keep in step with that
     * one is a second list to get wrong.
     *
     * The bank code `000` is allocated to no Belgian institution, exactly as
     * for the two unit accounts above, so none of these belongs to anybody.
     */
    public const SECTION_IBAN_FIRST_BBAN = 1001;

    /**
     * A section account's IBAN, with correct ISO 13616 check digits.
     *
     * They have to be right: FinanceService::updateAccount() validates
     * through IbanNormalizer::isValidFullIban() before the value is encrypted
     * and blind-indexed, so a hand-invented IBAN would be refused by the very
     * service this dataset insists on going through.
     */
    public static function sectionIban(int $index): string
    {
        $bban = sprintf('%012d', self::SECTION_IBAN_FIRST_BBAN + $index);
        // "BE" as digits is 1114, and the two check digits stand in as "00"
        // while they are being computed — the ISO rearrangement, exactly.
        $check = 98 - self::mod97($bban . '111400');

        return trim(chunk_split(sprintf('BE%02d%s', $check, $bban), 4, ' '));
    }

    /**
     * Mod 97 of a decimal string, taken piecewise: a sixteen-digit IBAN does
     * not fit in an int on every platform, and building the number first is
     * how that stops being true silently.
     */
    private static function mod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }

    /** @return list<string> the structured communications expected for a year */
    public static function communicationsFor(string $yearLabel): array
    {
        return array_map(
            static fn (string $base): string => StructuredCommunicationService::format($base),
            self::COTISATION_BASES[$yearLabel] ?? [],
        );
    }

    /** The IBAN as the "Numéro de compte" column carries it: no spaces. */
    public static function compactIban(string $iban): string
    {
        return str_replace(' ', '', $iban);
    }

    /** The relative path of one account's statement for one year. */
    public static function fileFor(string $yearLabel, string $accountHandle): string
    {
        return self::DIRECTORY . '/' . $yearLabel . '_' . $accountHandle . '.csv';
    }
}
