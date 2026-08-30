<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * A unit's year on a calendar, declared as data.
 *
 * **The weekly rhythm is a RULE here, never a list of occurrences.** A
 * Belgian section meets most Saturdays from mid-September to the end of June;
 * writing that out is thirty rows per section per year, five hundred and
 * forty rows in this dataset, in which nobody could see the rhythm any more
 * and any one of which could quietly drift onto a Sunday. So this table says
 * *which weekday, over which window, minus which weeks* and
 * CalendarSeeder::occurrencesOf() produces the dates. A change of rhythm is
 * then one edited line rather than a regenerated wall.
 *
 * Every date is an **offset in days from 1 September of the scout year's
 * start year** — the convention the rest of this directory already uses
 * (ExtrasBlueprint::dateIn()) — so an event can never wander outside its own
 * scout year, and the three years are described once rather than three times.
 *
 * **Two sections deliberately have no calendar content**: la Route, whose
 * members organise themselves rather than being convened, and Staff d'U,
 * which is not a section at all (UnitStaffSectionService synthesises it) and
 * whose meetings are the Conseils d'unité of UNIT_EVENTS below.
 */
final class CalendarBlueprint
{
    /**
     * Section handles that get no meetings, no camp and no weekend.
     *
     * Staff d'U is absent from UnitBlueprint::SECTIONS entirely, so it needs
     * no entry: it simply never appears in the loop.
     */
    public const SECTIONS_WITHOUT_RHYTHM = ['rou1'];

    /**
     * Belgian school holidays, as offset windows from 1 September, both
     * bounds inclusive.
     *
     * These are the Fédération Wallonie-Bruxelles rhythm since the 2022
     * reform — two weeks in autumn, two at Christmas, two in February, two
     * in spring — rounded to a fixed offset rather than computed from Easter.
     * The dataset speaks in offsets everywhere, and a movable feast would
     * make the one table a reader most wants to check by eye the one table
     * they cannot.
     *
     * A unit does meet during some holidays; what it does not do is meet
     * every week of them, and a calendar with no gaps at all is the thing
     * this table exists to avoid.
     *
     * @var list<array{label: string, from: int, to: int}>
     */
    public const SCHOOL_HOLIDAYS = [
        ['label' => "Congé d'automne", 'from' => 53, 'to' => 67],
        ['label' => 'Vacances d\'hiver', 'from' => 111, 'to' => 125],
        ['label' => 'Congé de détente', 'from' => 165, 'to' => 179],
        ['label' => 'Congé de printemps', 'from' => 235, 'to' => 249],
    ];

    /**
     * The weekly meeting, per branch.
     *
     * `weekday` is ISO-8601 (1 = Monday … 7 = Sunday); every branch here
     * meets on a Saturday, which is what a Belgian unit does, but the column
     * exists because the day is the first thing a maintainer would want to
     * change and it should not mean editing code.
     *
     * `from`/`to` bound the season. `skips` drops that many *further*
     * Saturdays on top of the school holidays — a long weekend, a
     * fédération-wide day, the Saturday nobody could staff — given as
     * 0-based positions in the season's remaining Saturdays so the table
     * stays readable when the window moves.
     *
     * @var array<string, array{weekday: int, from: int, to: int, title: string, startTime: string, endTime: string, place: string, skips: list<int>}>
     */
    public const MEETING_RULE = [
        'Baladins' => [
            'weekday' => 6, 'from' => 13, 'to' => 293,
            'title' => 'Réunion', 'startTime' => '14:00', 'endTime' => '17:00',
            'place' => 'Local des Baladins',
            'skips' => [1, 8, 19],
        ],
        'Louveteaux' => [
            'weekday' => 6, 'from' => 13, 'to' => 293,
            'title' => 'Réunion', 'startTime' => '14:00', 'endTime' => '17:30',
            'place' => 'Local des Louveteaux',
            'skips' => [4, 12, 22],
        ],
        'Éclaireurs' => [
            'weekday' => 6, 'from' => 13, 'to' => 293,
            'title' => 'Réunion', 'startTime' => '14:00', 'endTime' => '18:00',
            'place' => 'Local de la Troupe',
            'skips' => [2, 11, 15, 24],
        ],
        'Pionniers' => [
            'weekday' => 6, 'from' => 13, 'to' => 293,
            'title' => 'Réunion', 'startTime' => '18:00', 'endTime' => '22:00',
            'place' => 'Local du Poste',
            'skips' => [3, 5, 14, 21],
        ],
        'Iama' => [
            'weekday' => 6, 'from' => 20, 'to' => 286,
            'title' => 'Réunion', 'startTime' => '14:00', 'endTime' => '17:00',
            'place' => 'Local Iama',
            'skips' => [2, 6, 10, 16, 23],
        ],
    ];

    /**
     * The four fixed points of a section's year, per branch: a big day and a
     * weekend before Christmas, a big day and a weekend after it.
     *
     * `duration` is in days and is what CalendarEventService stores as the
     * event's end date; 0 is a single-day event, which is a NULL end date,
     * not a one-day range.
     *
     * `weekday` snaps the computed date FORWARD to that ISO weekday, which
     * is what keeps a « weekend » on a Friday and a « grande journée » on a
     * Saturday in all three years: 1 September is a different weekday every
     * year, so a bare offset would slide a weekend onto a Tuesday.
     *
     * @var array<string, list<array{day: int, weekday: int, duration: int, title: string, startTime: ?string, endTime: ?string, place: ?string}>>
     */
    public const SECTION_HIGHLIGHTS = [
        'Baladins' => [
            ['day' => 48, 'weekday' => 6, 'duration' => 0, 'title' => 'Grande journée des Baladins', 'startTime' => '09:30', 'endTime' => '17:00', 'place' => 'Bois de Lauzelle'],
            ['day' => 90, 'weekday' => 6, 'duration' => 1, 'title' => 'Weekend de la Saint-Nicolas', 'startTime' => null, 'endTime' => null, 'place' => 'Gîte de la Sapinière'],
            ['day' => 194, 'weekday' => 6, 'duration' => 0, 'title' => 'Journée jeux de piste', 'startTime' => '09:30', 'endTime' => '17:00', 'place' => 'Parc du Sart'],
            ['day' => 264, 'weekday' => 6, 'duration' => 1, 'title' => 'Weekend de printemps', 'startTime' => null, 'endTime' => null, 'place' => 'Ferme du Grand Pré'],
        ],
        'Louveteaux' => [
            ['day' => 41, 'weekday' => 6, 'duration' => 0, 'title' => 'Grande journée de la Meute', 'startTime' => '09:00', 'endTime' => '18:00', 'place' => 'Bois de Lauzelle'],
            ['day' => 83, 'weekday' => 5, 'duration' => 2, 'title' => 'Weekend de meute', 'startTime' => null, 'endTime' => null, 'place' => 'Gîte de la Sapinière'],
            ['day' => 187, 'weekday' => 6, 'duration' => 0, 'title' => 'Grand jeu de la Fleur Rouge', 'startTime' => '09:00', 'endTime' => '18:00', 'place' => 'Terrain du Sart'],
            ['day' => 257, 'weekday' => 5, 'duration' => 2, 'title' => 'Weekend de passage', 'startTime' => null, 'endTime' => null, 'place' => 'Ferme du Grand Pré'],
        ],
        'Éclaireurs' => [
            ['day' => 34, 'weekday' => 6, 'duration' => 0, 'title' => 'Grand jeu de rentrée', 'startTime' => '09:00', 'endTime' => '19:00', 'place' => 'Terrain du Sart'],
            ['day' => 76, 'weekday' => 5, 'duration' => 2, 'title' => 'Weekend de patrouille', 'startTime' => null, 'endTime' => null, 'place' => 'Gîte de la Sapinière'],
            ['day' => 201, 'weekday' => 6, 'duration' => 0, 'title' => 'Journée technique — hike et pionniérat', 'startTime' => '09:00', 'endTime' => '18:00', 'place' => 'Bois de Lauzelle'],
            ['day' => 250, 'weekday' => 5, 'duration' => 2, 'title' => 'Weekend hike', 'startTime' => null, 'endTime' => null, 'place' => 'Ardenne'],
        ],
        'Pionniers' => [
            ['day' => 27, 'weekday' => 6, 'duration' => 0, 'title' => 'Lancement du projet', 'startTime' => '18:00', 'endTime' => '23:00', 'place' => 'Local du Poste'],
            ['day' => 69, 'weekday' => 5, 'duration' => 2, 'title' => 'Weekend de projet', 'startTime' => null, 'endTime' => null, 'place' => 'Gîte de la Sapinière'],
            ['day' => 208, 'weekday' => 6, 'duration' => 0, 'title' => 'Journée chantier', 'startTime' => '09:00', 'endTime' => '18:00', 'place' => 'Ferme du Grand Pré'],
            ['day' => 243, 'weekday' => 5, 'duration' => 2, 'title' => 'Weekend de préparation du camp', 'startTime' => null, 'endTime' => null, 'place' => 'Ardenne'],
        ],
        'Iama' => [
            ['day' => 55, 'weekday' => 6, 'duration' => 0, 'title' => 'Journée découverte', 'startTime' => '10:00', 'endTime' => '16:00', 'place' => 'Local Iama'],
            ['day' => 97, 'weekday' => 6, 'duration' => 1, 'title' => 'Weekend adapté', 'startTime' => null, 'endTime' => null, 'place' => 'Gîte de la Sapinière'],
            ['day' => 180, 'weekday' => 6, 'duration' => 0, 'title' => 'Journée sportive', 'startTime' => '10:00', 'endTime' => '16:00', 'place' => 'Centre sportif du Sart'],
            ['day' => 271, 'weekday' => 6, 'duration' => 1, 'title' => 'Weekend au vert', 'startTime' => null, 'endTime' => null, 'place' => 'Ferme du Grand Pré'],
        ],
    ];

    /**
     * The summer camp, per branch: when it starts (late July) and how many
     * nights it lasts.
     *
     * **The youngest go for less time than the oldest**, which is the whole
     * point of having the column: three days for the Baladins, a fortnight
     * for the Pionniers. A dataset where every camp is nine days long
     * teaches a reader nothing about what the site does with a range.
     *
     * @var array<string, array{day: int, duration: int, title: string}>
     */
    public const CAMPS = [
        'Baladins' => ['day' => 328, 'duration' => 3, 'title' => 'Camp des Baladins'],
        'Louveteaux' => ['day' => 323, 'duration' => 7, 'title' => 'Camp de la Meute'],
        'Éclaireurs' => ['day' => 320, 'duration' => 11, 'title' => 'Camp de la Troupe'],
        'Pionniers' => ['day' => 318, 'duration' => 13, 'title' => 'Camp du Poste'],
        'Iama' => ['day' => 325, 'duration' => 6, 'title' => 'Camp Iama'],
    ];

    /**
     * Where each section camps. Per SECTION and not per branch: two
     * Baladins sections of the same unit do not camp in the same field, and
     * a dataset that said they did would hide the one thing a chief looks
     * for on that page.
     *
     * @var array<string, string>
     */
    public const CAMP_PLACES = [
        'bal1' => 'Ferme du Grand Pré (Havelange)',
        'bal2' => 'Gîte de la Sapinière (Ferrières)',
        'lou1' => 'Prairie de Bomal',
        'lou2' => 'Ferme de Wéris',
        'ecl1' => 'Plaine de Vresse-sur-Semois',
        'pio1' => 'Hautes Fagnes — itinérant',
        'iam1' => 'Domaine de Chevetogne',
    ];

    /**
     * A day-shift applied to every punctual event of a year, so the three
     * years are not carbon copies of one another. Meetings are unaffected:
     * they follow the weekday, not the offset.
     *
     * @var array<string, int>
     */
    public const YEAR_SHIFT = [
        '2024-2025' => 0,
        '2025-2026' => 6,
        '2026-2027' => -4,
    ];

    /**
     * The unit-wide calendar — CalendarService::ensureDefaultCalendar()'s
     * « Animateurs », chief-only.
     *
     * One Temps d'unité (a weekend) a year and a handful of Conseils d'unité
     * on Saturday mornings, plus the three unit gatherings the dataset has
     * always carried. Chief-only visibility is exactly right for these: a
     * Conseil d'unité is a staff meeting, and its presence on a calendar
     * anonymous visitors cannot see is one of the things this dataset lets
     * somebody check.
     *
     * @var list<array{day: int, weekday: int, duration: int, title: string, startTime: ?string, endTime: ?string, place: ?string}>
     */
    public const UNIT_EVENTS = [
        ['day' => 6, 'weekday' => 6, 'duration' => 0, 'title' => "Conseil d'unité", 'startTime' => '09:00', 'endTime' => '12:00', 'place' => "Local d'unité"],
        ['day' => 20, 'weekday' => 6, 'duration' => 0, 'title' => "Fête d'unité", 'startTime' => '10:00', 'endTime' => '18:00', 'place' => 'Terrain du Sart'],
        ['day' => 62, 'weekday' => 5, 'duration' => 2, 'title' => "Temps d'unité", 'startTime' => null, 'endTime' => null, 'place' => 'Gîte de la Sapinière'],
        ['day' => 104, 'weekday' => 6, 'duration' => 0, 'title' => "Conseil d'unité", 'startTime' => '09:00', 'endTime' => '12:00', 'place' => "Local d'unité"],
        ['day' => 174, 'weekday' => 6, 'duration' => 0, 'title' => "Conseil d'unité", 'startTime' => '09:00', 'endTime' => '12:00', 'place' => "Local d'unité"],
        ['day' => 230, 'weekday' => 6, 'duration' => 0, 'title' => 'Passage des sections', 'startTime' => '14:00', 'endTime' => '18:00', 'place' => "Local d'unité"],
        ['day' => 279, 'weekday' => 6, 'duration' => 0, 'title' => "Conseil d'unité — bilan", 'startTime' => '09:00', 'endTime' => '12:00', 'place' => "Local d'unité"],
    ];
}
