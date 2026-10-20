<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The custom mailing lists, as data (chantier #729, ARCHITECTURE.md
 * §8.71septies).
 *
 * A custom list is a set of blocks of two kinds, added up: member groups
 * (functions × sections × badges, resolved for the year chosen when the
 * e-mail is written) and contact groups (outside people, kept up to date
 * by an Excel file only). The two lists below are the two shapes the
 * e-mail page words differently (`views/compose.html.twig`): one that
 * mixes both kinds — the years then concern its member groups only — and
 * one made of contacts only, for which the year changes nothing.
 *
 * A member group is written as the function labels it names — the only
 * axis these two need; sections and badges stay « Peu importe ». Labels,
 * never ids: the ids come from the Desk import the build has just
 * replayed, and the labels are UnitBlueprint's vocabulary. A label the import does not produce is a
 * build failure, not a group that quietly names nobody.
 *
 * Contact groups name a committed file under `mailing/`, read by the real
 * analysis (`ContactGroupImportService::analyse()`) exactly as the dialog
 * reads an upload. The files are written once by hand, not generated:
 * nothing in DatasetGenerator depends on them, and an .xlsx carries
 * timestamps `generate.php --check` could not compare byte for byte.
 */
final class MailingListBlueprint
{
    /** Where the contact files live, relative to the dataset root. */
    public const DIRECTORY = 'mailing';

    /**
     * @var list<array{
     *     name: string,
     *     description: string,
     *     memberGroups: list<list<string>>,
     *     contactGroups: list<array{name: string, file: string}>
     * }>
     */
    public const LISTS = [
        [
            'name' => 'Staff élargi',
            'description' => 'Le Staff d\'U, les animateurs responsables et les anciens qui donnent encore un coup '
                . 'de main.',
            'memberGroups' => [
                // The unit staff, whichever of the three unit functions they hold.
                ['Animateur d\'unité', 'Équipier d\'unité', 'Collaborateur d\'unité'],
                // One per section and per year (UnitBlueprint::SECTION_LEAD_FUNCTION).
                [UnitBlueprint::SECTION_LEAD_FUNCTION],
            ],
            'contactGroups' => [
                ['name' => 'Anciens animateurs', 'file' => 'anciens-animateurs.xlsx'],
            ],
        ],
        [
            'name' => 'Partenaires locaux',
            'description' => 'La commune, la paroisse et les voisins qui accueillent l\'unité.',
            'memberGroups' => [],
            'contactGroups' => [
                ['name' => 'Partenaires de l\'unité', 'file' => 'partenaires-locaux.xlsx'],
            ],
        ],
    ];
}
