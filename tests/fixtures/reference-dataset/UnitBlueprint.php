<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The unit, declared as data.
 *
 * Everything a maintainer would want to change about the shape of the dataset
 * — how many sections, how big each one is in each year, which functions
 * exist, which names and towns the generator draws from — lives here, in
 * tables. Changing the dataset means editing a table and re-running
 * `generate.php`, never editing 900 lines of CSV.
 *
 * All data is fictional. Emails use the RFC 2606 reserved domains, street
 * names are invented, phone numbers are structurally unassignable, and no
 * detail is taken from any real unit.
 */
final class UnitBlueprint
{
    /**
     * The three scout years, oldest first.
     *
     * Deliberately hard-coded rather than computed from today's date: the
     * generated CSVs are committed and compared byte-for-byte by
     * `generate.php --check`, so a date-relative label would make that check
     * fail every 1 September with nothing in the dataset having changed. The
     * consequence — the site's own current year is date-computed and will
     * eventually sit outside these three — is documented in README.md §5.
     */
    public const YEARS = ['2024-2025', '2025-2026', '2026-2027'];

    /** Fictional unit identifier ("Groupe unités" column). */
    public const UNIT_GROUP = 'ZZ001';

    /** "Fonction au sein de l'unité" column — constant across the export. */
    public const UNIT_CODE = 'ZZ001U';

    /** Seed of the deterministic generator. Change it and every CSV changes. */
    public const SEED = 20260901;

    /**
     * Sections, keyed by an internal handle used nowhere in the CSV.
     *
     * `name` is the "Section" column, which carries BOTH the section's code
     * and its name — ImportSectionRepository keys on that exact string, so
     * renaming one between two years creates a brand-new section rather than
     * renaming the existing one. Never rename one here without meaning it.
     *
     * `ignoredCode` is the "SECTION" (all-caps) column, which the parser
     * never reads. Each is deliberately the code of the NEXT section in this
     * table rather than its own, so that code which started reading it would
     * put everyone in the wrong section loudly instead of quietly working.
     *
     * `branch` must contain a substring AgeBranchRepository::
     * canonicalSortOrder() recognises — and must NOT contain "unité", which
     * that method routes to the Staff d'U slot (50).
     *
     * `email` fills `sections.email`, the one section column no Desk export
     * carries: it is typed on Configuration > Config Desk and read by every
     * page that offers "écrire à la section". The column existed and was
     * empty in every earlier version of this dataset, which made those pages
     * look broken rather than unconfigured. The domain is the unit's own,
     * under the RFC 2606 reserved `example.net` — one domain for the unit,
     * never a real one and never the same as the members' `example.com`.
     *
     * @var array<string, array{name: string, branch: string, ignoredCode: string, email: string}>
     */
    public const SECTIONS = [
        'bal1' => ['name' => 'Ribambelle Bleue',     'branch' => 'Baladins',   'ignoredCode' => 'ZZ001B2', 'email' => 'ribambelle-bleue@' . self::MAIL_DOMAIN],
        'bal2' => ['name' => 'Ribambelle Verte',     'branch' => 'Baladins',   'ignoredCode' => 'ZZ001L1', 'email' => 'ribambelle-verte@' . self::MAIL_DOMAIN],
        'lou1' => ['name' => 'Meute de Seeonee',     'branch' => 'Louveteaux', 'ignoredCode' => 'ZZ001L2', 'email' => 'seeonee@' . self::MAIL_DOMAIN],
        'lou2' => ['name' => 'Meute de Waingunga',   'branch' => 'Louveteaux', 'ignoredCode' => 'ZZ001E1', 'email' => 'waingunga@' . self::MAIL_DOMAIN],
        'ecl1' => ['name' => 'Troupe du Faucon',     'branch' => 'Éclaireurs', 'ignoredCode' => 'ZZ001P1', 'email' => 'faucon@' . self::MAIL_DOMAIN],
        'pio1' => ['name' => "Poste de l'Escaut",    'branch' => 'Pionniers',  'ignoredCode' => 'ZZ001R1', 'email' => 'escaut@' . self::MAIL_DOMAIN],
        'rou1' => ['name' => 'Route de Compostelle', 'branch' => 'Route',      'ignoredCode' => 'ZZ001I1', 'email' => 'compostelle@' . self::MAIL_DOMAIN],
        'iam1' => ['name' => 'Iama Horizon',         'branch' => 'Iama',       'ignoredCode' => 'ZZ001B1', 'email' => 'iama@' . self::MAIL_DOMAIN],
    ];

    /**
     * The unit's own mail domain, RFC 2606 reserved so it can never resolve.
     * Deliberately different from the members' `example.com`: a section
     * address is the unit's, a member's address is the family's, and the two
     * being visibly different is what makes a mis-wired "reply to" obvious.
     */
    public const MAIL_DOMAIN = 'zz001.example.net';

    /**
     * Staff d'U never appears in a Desk export — UnitStaffSectionService
     * synthesises it — so its address cannot live in SECTIONS above.
     */
    public const UNIT_STAFF_EMAIL = 'staff@' . self::MAIL_DOMAIN;

    /**
     * Target headcount per section per year: [animés, cadres].
     *
     * These are the realistic proportions of a Belgian unit, and they are the
     * authority. The photo lot is NOT: a staff is never shrunk to fit the
     * number of portraits available, and a cadre with no photo keeps the
     * initials avatar member_photo() renders by default — a perfectly normal
     * state of the site, and one the dataset should contain.
     *
     * A section absent from a year's row does not exist that year:
     *   - bal2 is missing from 2024-2025 — a section that only appears in A2.
     *   - iam1 is [0, 0] in 2026-2027 — a section emptied but never deleted,
     *     which MappingResolver::deactivateAllSections() must leave inactive.
     * Those two are scenarios 16 and 15; the zero row and the missing row are
     * deliberately different shapes, because they exercise different code.
     *
     * @var array<string, array<string, array{int, int}>>
     */
    public const HEADCOUNT = [
        '2024-2025' => [
            'bal1' => [20, 4],
            'lou1' => [26, 5],
            'lou2' => [24, 3],
            'ecl1' => [32, 6],
            'pio1' => [22, 4],
            'rou1' => [13, 3],
            'iam1' => [8, 3],
        ],
        '2025-2026' => [
            'bal1' => [16, 3],
            'bal2' => [15, 3],
            'lou1' => [24, 5],
            'lou2' => [22, 3],
            'ecl1' => [30, 6],
            'pio1' => [20, 4],
            'rou1' => [12, 3],
            'iam1' => [6, 3],
        ],
        '2026-2027' => [
            'bal1' => [17, 4],
            'bal2' => [16, 3],
            'lou1' => [25, 5],
            'lou2' => [22, 4],
            'ecl1' => [31, 6],
            'pio1' => [20, 4],
            'rou1' => [13, 3],
            'iam1' => [0, 0],
        ],
    ];

    /** Size of the unit staff (Staff d'U) per year. */
    public const UNIT_STAFF_SIZE = [
        '2024-2025' => 4,
        '2025-2026' => 4,
        '2026-2027' => 5,
    ];

    /**
     * Every FONCTION the dataset uses, and the role Config Desk will be asked
     * to confirm for it (IT-05 reads this table; the CSV itself never carries
     * a role — every new function imports as 'identified', unconfirmed).
     *
     * `Accompagnateur d'unité` appears only in 2026-2027: it is the brand-new
     * function that must show up unconfirmed and block on confirmation.
     * `Animateur candidat` is how Desk marks someone whose obligations are
     * incomplete — nothing consumes the word "candidat" today; the future
     * Encadrement module will.
     *
     * `Chef de section` is the function the trombinoscope's "responsable"
     * comes from: Modules\Trombinoscope\Repository\FunctionFlagsRepository
     * flags it `is_lead`, and Service\TrombinoscopeService promotes whoever
     * holds it to the top of the section's staff. Exactly ONE cadre per
     * section per year carries it (PopulationBuilder::designateSectionLeads()),
     * because a flag every animateur carried would make the "responsable"
     * whichever row the query happened to return first.
     *
     * @var array<string, string>
     */
    public const FUNCTIONS = [
        'Animé' => 'identified',
        'Animateur' => 'chief',
        'Animateur candidat' => 'chief',
        'Chef de section' => 'chief',
        'Chef d\'unité' => 'admin',
        'Intendant d\'unité' => 'intendant',
        'Trésorier d\'unité' => 'intendant',
        'Accompagnateur d\'unité' => 'identified',
    ];

    /**
     * The FONCTION whose `is_lead` flag the trombinoscope reads. Named once
     * here so the generator that hands it out and the seeder that flags it
     * cannot drift apart.
     */
    public const SECTION_LEAD_FUNCTION = 'Chef de section';

    /** Functions held at unit level: no Branche, no Section, no Date début. */
    public const UNIT_LEVEL_FUNCTIONS = [
        'Chef d\'unité',
        'Intendant d\'unité',
        'Trésorier d\'unité',
        'Accompagnateur d\'unité',
    ];

    /** "Tarif" column values. A member moving between two of them is scenario 23. */
    public const FEE_CODES = [
        'anime' => 'Tarif normal',
        'anime_reduit' => 'Tarif réduit',
        'cadre' => 'Tarif animateur',
    ];

    public const FORMATION_LEVELS = ['Aucun', 'Formation de base', 'Formation animateur', 'Formation avancée'];

    /**
     * Share of child members (Baladins, Louveteaux, Éclaireurs) carrying a
     * second address — separated parents, mostly. Tuned so each file lands in
     * the 250-300 line band a real export of this size produces.
     */
    public const TWO_ADDRESS_PERCENT_CHILD = 70;
    public const TWO_ADDRESS_PERCENT_TEEN = 35;
    public const TWO_ADDRESS_PERCENT_ADULT = 25;

    /** Share of animés who leave the unit between two years. */
    public const CHURN_PERCENT = 9;

    /**
     * Given names, split by the Genre the export carries.
     *
     * The split is not 50/50 on purpose: a unit is rarely balanced, and
     * Prévisions and Statistiques have nothing to show on a dataset where
     * every chart is a straight line (scenario 24). The generator draws
     * roughly 46% F / 54% M — see PopulationBuilder::GENDER_F_PERCENT.
     *
     * @var non-empty-list<string>
     */
    public const FIRST_NAMES_F = [
        'Alice', 'Amélie', 'Anaïs', 'Camille', 'Céleste', 'Charlotte', 'Chloé',
        'Clara', 'Éléonore', 'Élise', 'Emma', 'Fanny', 'Gaëlle', 'Héloïse',
        'Inès', 'Jeanne', 'Julie', 'Justine', 'Léa', 'Lucie', 'Manon',
        'Margaux', 'Marie', 'Mathilde', 'Maud', 'Noémie', 'Océane', 'Pauline',
        'Romane', 'Sarah', 'Sofia', 'Zoé',
    ];

    /** @var non-empty-list<string> */
    public const FIRST_NAMES_M = [
        'Adrien', 'Antoine', 'Arthur', 'Augustin', 'Baptiste', 'Benoît',
        'Corentin', 'Damien', 'Élie', 'Émile', 'Gauthier', 'Grégoire', 'Hugo',
        'Jules', 'Léon', 'Louis', 'Lucas', 'Martin', 'Mathis', 'Maxime',
        'Nathan', 'Nicolas', 'Noé', 'Olivier', 'Quentin', 'Raphaël', 'Rémi',
        'Simon', 'Thibault', 'Thomas', 'Victor', 'Vincent',
    ];

    /**
     * Surnames. The four at the top are scenario 22 — an accent, an
     * apostrophe, a particle and a hyphen — and are guaranteed to be used:
     * PopulationBuilder assigns them first, before drawing from the rest.
     *
     * @var non-empty-list<string>
     */
    public const LAST_NAMES = [
        'Étienne', "N'Diaye", 'Van der Meulen', 'Dubois-Lefèvre',
        'Bastin', 'Beauduin', 'Berger', 'Bodart', 'Collard', 'Cornil',
        'Delvaux', 'Denis', 'Dewulf', 'Dumont', 'Fontaine', 'Gérard',
        'Gilson', 'Hallet', 'Henrion', 'Jacquemin', 'Lambert', 'Laurent',
        'Leclercq', 'Lejeune', 'Lemaire', 'Marchal', 'Massart', 'Mathieu',
        'Nihoul', 'Pirard', 'Poncelet', 'Renard', 'Simonis', 'Thiry',
        'Toussaint', 'Vandenberghe', 'Verhoeven', 'Wathelet', 'Willems',
    ];

    /**
     * Invented street names. Never a real street of a real unit.
     *
     * @var non-empty-list<string>
     */
    public const STREETS = [
        'Rue du Feu de Camp', 'Avenue des Quatre Vents', 'Clos de la Hutte',
        'Chemin des Marmites', 'Rue de la Corde Lisse', 'Drève du Totem',
        'Sentier des Sizaines', 'Rue du Foulard', 'Allée des Tentes Canadiennes',
        'Chaussée du Grand Feu', 'Venelle des Sarbacanes', 'Rue de la Boussole',
        'Clos des Trois Nœuds', 'Avenue du Sac à Dos', 'Chemin de la Popote',
        'Rue des Piquets', 'Square de la Veillée', 'Chemin du Bivouac',
    ];

    /**
     * Real Belgian towns with their real postal codes — the one category of
     * real data that is allowed here, because a postal code is not personal
     * data and a wrong one would make the address normalisation of
     * Core\Member\AddressNormalizer meaningless to look at.
     *
     * @var non-empty-list<array{string, string}>
     */
    public const TOWNS = [
        ['1000', 'Bruxelles'], ['1030', 'Schaerbeek'], ['1050', 'Ixelles'],
        ['1180', 'Uccle'], ['1300', 'Wavre'], ['1310', 'La Hulpe'],
        ['1330', 'Rixensart'], ['1348', 'Louvain-la-Neuve'], ['1400', 'Nivelles'],
        ['1420', "Braine-l'Alleud"], ['1470', 'Genappe'], ['1490', 'Court-Saint-Étienne'],
        ['4000', 'Liège'], ['5000', 'Namur'], ['5100', 'Jambes'],
        ['6000', 'Charleroi'], ['7000', 'Mons'],
    ];

    /** @var non-empty-list<string> */
    public const TOTEMS = [
        'Okapi', 'Loutre', 'Chamois', 'Fennec', 'Guépard', 'Bouquetin',
        'Macareux', 'Ocelot', 'Sittelle', 'Wallaby', 'Zébu', 'Cabiai',
        'Ibis', 'Lynx', 'Panda', 'Suricate', 'Tamanoir', 'Vanneau',
    ];

    /** @var non-empty-list<string> */
    public const QUALIS = [
        'Espiègle', 'Tenace', 'Jovial', 'Placide', 'Vif', 'Serein',
        'Rieur', 'Constant', 'Malicieux', 'Attentif', 'Bavard', 'Posé',
    ];

    /** @var non-empty-list<string> */
    public const PATROLS_YOUNG = ['Les Tigres', 'Les Renards', 'Les Écureuils', 'Les Blaireaux', 'Les Hiboux'];

    /** @var non-empty-list<string> */
    public const PATROLS_TEEN = ['Les Aigles', 'Les Loups', 'Les Cerfs', 'Les Faucons', 'Les Bisons'];

    /** @var non-empty-list<string> */
    public const INSURANCES = ['Assurance sport', 'Assurance familiale étendue', 'Assurance rapatriement'];

    /**
     * The canonical age bracket of each branch, mirroring
     * Core\Member\MemberYearService::BRANCHES. Route and Iama are absent from
     * that constant on purpose — their members have no year-in-branch and no
     * branch colour, and the dataset must contain some of them so that
     * "no branch" is a state the site is seen handling.
     *
     * @var array<string, array{int, int}>
     */
    public const BRANCH_AGES = [
        'Baladins' => [6, 7],
        'Louveteaux' => [8, 11],
        'Éclaireurs' => [12, 15],
        'Pionniers' => [16, 17],
        'Route' => [18, 21],
        // Iama spans a much wider range than the four animés branches on
        // purpose: it is a mixed-age branch, so its members are the ones who
        // prove the site copes with a member who has no year-in-branch and no
        // branch colour at any age.
        'Iama' => [12, 25],
    ];

    /** The branch an animé moves up to when they age out of theirs. */
    public const BRANCH_AFTER = [
        'Baladins' => 'Louveteaux',
        'Louveteaux' => 'Éclaireurs',
        'Éclaireurs' => 'Pionniers',
        'Pionniers' => 'Route',
    ];

    /**
     * The reference calendar year of a scout year is its START year — the
     * same rule as MemberYearService::referenceYearFromScoutYearLabel(), and
     * never today's date, so a member's branch never shifts mid-year.
     */
    public static function referenceYear(string $yearLabel): int
    {
        return (int) substr($yearLabel, 0, 4);
    }

    /** 1 September of the scout year's start year, as Desk writes dates. */
    public static function startDate(string $yearLabel): string
    {
        return '01/09/' . self::referenceYear($yearLabel);
    }

    /** @return list<string> section handles that exist in this year */
    public static function sectionsIn(string $yearLabel): array
    {
        $handles = [];
        foreach (self::HEADCOUNT[$yearLabel] as $handle => $counts) {
            if (self::isPopulated($counts)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Behind a typed parameter rather than compared inline: read straight off
     * the constant, PHPStan narrows each row to its literal values and calls
     * the `[0, 0]` one an impossible comparison.
     *
     * @param array{int, int} $counts
     */
    private static function isPopulated(array $counts): bool
    {
        return $counts[0] + $counts[1] > 0;
    }

    /** @return list<string> section handles of a branch that exist in this year */
    public static function sectionsOfBranchIn(string $branch, string $yearLabel): array
    {
        $handles = [];
        foreach (self::sectionsIn($yearLabel) as $handle) {
            if (self::SECTIONS[$handle]['branch'] === $branch) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }
}
