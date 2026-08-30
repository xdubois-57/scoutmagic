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
 * **This file used to hold every extra there was, and no longer does.** Once
 * the dataset grew a calendar rhythm, news articles, camps, registrations,
 * rentals, banners and a payment campaign, one table describing all of them
 * was a table nobody could read. Each domain now has its own `*Blueprint`
 * next door, and its own `*Seeder` to apply it. What is left here is the
 * MEMBER-level extras — the things Desk exports a member without: a year
 * shifted, a departure announced, a photo, a cotisation still owed.
 *
 * **The covered subset is still deliberate and still named** (README.md §8.3):
 * section documents and discussion groups are not seeded, and that section
 * says so with the reason.
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

    /**
     * The email address of every section, and of the synthesised Staff d'U.
     *
     * `sections.email` is the one section column no Desk export carries — it
     * is typed on Config Desk — and it was empty in every earlier version of
     * this dataset, which made the "écrire à la section" surfaces look broken
     * rather than unconfigured. The addresses themselves live in
     * UnitBlueprint, beside the sections they belong to.
     *
     * @return array<string, string> section handle => address
     */
    public static function sectionEmails(): array
    {
        $emails = [];
        foreach (UnitBlueprint::SECTIONS as $handle => $section) {
            $emails[$handle] = $section['email'];
        }

        return $emails;
    }

    /** 1 September of the scout year's start year, plus $days. */
    public static function dateIn(string $yearLabel, int $days): string
    {
        $start = new \DateTimeImmutable(sprintf('%04d-09-01', UnitBlueprint::referenceYear($yearLabel)));

        return $start->modify('+' . $days . ' days')->format('Y-m-d');
    }
}
