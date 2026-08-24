<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Everything Desk knows nothing about, declared as data.
 *
 * A Desk export carries members, functions, sections and fees. It has no idea
 * that a chief held a member back a year, that somebody has announced they are
 * leaving, who the unit's nurse is, what the section is doing next weekend, or
 * which payments the treasurer is still waiting for. All of that is
 * ScoutMagic's own, and all of it is declared here so the dataset can be
 * maintained by editing a table.
 *
 * **The covered subset is deliberate and named** (README.md §8.3). The chantier
 * asked for broad module coverage rather than exhaustiveness, and explicitly
 * allowed proposing a subset instead of shipping an undocumented partial one.
 * What is here — photos, offsets, departures, badges, expected receivables,
 * calendar events — is what the rest of the dataset already needs to make
 * sense. What is not is listed in that same README section, with the reason.
 */
final class ExtrasBlueprint
{
    /**
     * Members a chief has shifted a year up or down.
     *
     * `scout_year_offset` is the one `member_years` column Desk cannot express
     * (ARCHITECTURE.md §8.1): it is set from the member page for somebody who
     * skipped or repeated a year, and a new year's row inherits it from the
     * member's most recent EARLIER year, ordered by start_date.
     *
     * `T0009` is scenario 5 and the reason that ordering matters: the member
     * is absent in A2, so A3 has to reach back across the gap to A1. Setting
     * the offset on A1 — the year the chief would actually have opened — is
     * what makes the inheritance observable at all.
     *
     * @var array<string, array<string, int>> Tiers => scout year => offset
     */
    public const SCOUT_YEAR_OFFSETS = [
        'T0009' => ['2024-2025' => 1],
        'T0003' => ['2024-2025' => -1],
    ];

    /**
     * Members marked as leaving, with the comment a chief typed
     * (Core\Member\DepartureService, ARCHITECTURE.md §8.32).
     *
     * A departure marking is a forecast, not a fact: it is recorded on the
     * year the member is still in. `T0008` really does disappear from the next
     * export (scenario 4), and `T0025` is the sibling who leaves (scenario 18)
     * — so the dataset contains both a marking that came true and one made on
     * a member who is still there, which is what a real "Départs" grid looks
     * like in March.
     *
     * @var list<array{tiers: string, year: string, comment: string}>
     */
    public const DEPARTURES = [
        ['tiers' => 'T0008', 'year' => '2024-2025', 'comment' => 'Déménage en Flandre à la fin de l\'année.'],
        ['tiers' => 'T0025', 'year' => '2024-2025', 'comment' => 'Arrête pour se consacrer au conservatoire.'],
        ['tiers' => 'T0004', 'year' => '2025-2026', 'comment' => 'Hésite encore — à recontacter en août.'],
    ];

    /**
     * Badge assignments (Core\Badge). `Infirmier` and `Trésorier` are the two
     * badges BadgeService::ensureDefaults() seeds; the referent badges are
     * created per section by syncSectionReferentBadges() and are not assigned
     * here.
     *
     * @var list<array{tiers: string, year: string, badge: string}>
     */
    public const BADGES = [
        ['tiers' => 'T0017', 'year' => '2024-2025', 'badge' => 'Trésorier'],
        ['tiers' => 'T0017', 'year' => '2025-2026', 'badge' => 'Trésorier'],
        ['tiers' => 'T0017', 'year' => '2026-2027', 'badge' => 'Trésorier'],
        ['tiers' => 'T0014', 'year' => '2025-2026', 'badge' => 'Infirmier'],
        ['tiers' => 'T0018', 'year' => '2026-2027', 'badge' => 'Infirmier'],
    ];

    /**
     * Calendar events, one per section calendar plus a couple of unit-wide
     * ones. Dates are expressed as an offset in days from 1 September of the
     * scout year's start year, so an event never wanders outside its year.
     *
     * `section` is a handle of UnitBlueprint::SECTIONS, or null for the
     * default unit calendar.
     *
     * @var list<array{year: string, section: ?string, day: int, title: string, duration: int, location: ?string}>
     */
    public const CALENDAR_EVENTS = [
        ['year' => '2024-2025', 'section' => null, 'day' => 20, 'title' => "Fête d'unité", 'duration' => 0, 'location' => 'Terrain du Sart'],
        ['year' => '2024-2025', 'section' => 'lou1', 'day' => 34, 'title' => 'Réunion de rentrée', 'duration' => 0, 'location' => 'Local des Louveteaux'],
        ['year' => '2024-2025', 'section' => 'ecl1', 'day' => 96, 'title' => 'Weekend de Toussaint', 'duration' => 2, 'location' => 'Gîte de la Sapinière'],
        ['year' => '2024-2025', 'section' => 'pio1', 'day' => 280, 'title' => 'Camp d\'été', 'duration' => 9, 'location' => 'Ferme du Grand Pré'],
        ['year' => '2025-2026', 'section' => null, 'day' => 18, 'title' => "Temps d'unité", 'duration' => 0, 'location' => null],
        ['year' => '2025-2026', 'section' => 'bal2', 'day' => 40, 'title' => 'Première réunion de la Ribambelle Verte', 'duration' => 0, 'location' => 'Local des Baladins'],
        ['year' => '2025-2026', 'section' => 'lou2', 'day' => 150, 'title' => 'Grande journée', 'duration' => 0, 'location' => 'Bois de Lauzelle'],
        ['year' => '2026-2027', 'section' => null, 'day' => 25, 'title' => 'Passage des sections', 'duration' => 0, 'location' => 'Local d\'unité'],
        ['year' => '2026-2027', 'section' => 'ecl1', 'day' => 285, 'title' => 'Camp d\'été', 'duration' => 9, 'location' => 'Ardenne'],
    ];

    /**
     * The label put on each expected receivable, by scout year. One receivable
     * per structured communication of BankBlueprint::COTISATION_BASES, on the
     * unit account — which is what turns the twenty uncategorised membership
     * payments on the statements into a reconciliation instead of a mystery.
     *
     * @var array<string, string>
     */
    public const RECEIVABLE_LABELS = [
        '2024-2025' => 'Cotisation 2024-2025',
        '2025-2026' => 'Cotisation 2025-2026',
        '2026-2027' => 'Cotisation 2026-2027',
    ];

    /**
     * What a membership costs, in cents. Deliberately NOT the amount actually
     * paid on the statement: the payments are drawn between 35 € and 95 €, so
     * some households are square, some underpaid and some overpaid — which is
     * the only state in which a reconciliation page is worth looking at.
     */
    public const RECEIVABLE_AMOUNT_CENTS = 6500;

    /** The module name recorded as the source of these receivables. */
    public const RECEIVABLE_SOURCE_MODULE = 'reference_dataset';

    /** 1 September of the scout year's start year, plus $days. */
    public static function dateIn(string $yearLabel, int $days): string
    {
        $start = new \DateTimeImmutable(sprintf('%04d-09-01', UnitBlueprint::referenceYear($yearLabel)));

        return $start->modify('+' . $days . ' days')->format('Y-m-d');
    }
}
