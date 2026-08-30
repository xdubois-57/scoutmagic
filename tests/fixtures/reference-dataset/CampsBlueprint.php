<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Modules\Camps\Repository\Camp;

/**
 * The unit's camp places and the stays it has spent in them, as data.
 *
 * Five places, between one and three stays each: enough for the module's
 * list, its map and its per-place history to have something to show, and few
 * enough that a reader can hold the whole thing in their head.
 *
 * **Dates are absolute, and that is the exception in this directory.** Every
 * other table here speaks in offsets from 1 September because it belongs to a
 * scout year; a camp place's history does not — it is a fact about the past,
 * and the module's own page sorts stays by date across years. They are
 * anchored on the dataset's three scout years all the same: `past` stays are
 * the summers of 2022 through 2026, `future` ones are 2027 and 2028, so the
 * "à venir / passés" split (CampService::split()) has something on both sides
 * of any plausible build date.
 *
 * The addresses are real Belgian towns with their real postal codes — the one
 * category of real data this dataset allows (UnitBlueprint::TOWNS says why) —
 * but no place, farm or contact here belongs to anybody: the names are
 * invented and the street numbers with them.
 */
final class CampsBlueprint
{
    /**
     * @var list<array{
     *   handle: string, name: string, address: ?string, postalCode: ?string,
     *   city: ?string, country: ?string, website: ?string
     * }>
     */
    public const PLACES = [
        [
            'handle' => 'grandpre',
            'name' => 'Ferme du Grand Pré',
            'address' => 'Chemin des Fenaisons 12',
            'postalCode' => '5370',
            'city' => 'Havelange',
            'country' => 'Belgique',
            'website' => 'https://example.org/ferme-du-grand-pre',
        ],
        [
            'handle' => 'sapiniere',
            'name' => 'Gîte de la Sapinière',
            'address' => 'Route des Bruyères 3',
            'postalCode' => '4190',
            'city' => 'Ferrières',
            'country' => 'Belgique',
            'website' => null,
        ],
        [
            'handle' => 'bomal',
            'name' => 'Prairie de Bomal',
            'address' => null,
            'postalCode' => '6941',
            'city' => 'Durbuy',
            'country' => 'Belgique',
            'website' => null,
        ],
        [
            'handle' => 'vresse',
            'name' => 'Plaine de Vresse-sur-Semois',
            'address' => 'Rue du Ruisseau 44',
            'postalCode' => '5550',
            'city' => 'Vresse-sur-Semois',
            'country' => 'Belgique',
            'website' => 'https://example.org/plaine-de-vresse',
        ],
        [
            'handle' => 'chevetogne',
            'name' => 'Domaine de Chevetogne',
            'address' => 'Domaine 1',
            'postalCode' => '5590',
            'city' => 'Ciney',
            'country' => 'Belgique',
            'website' => null,
        ],
    ];

    /**
     * The stays, keyed by nothing: each names its place by handle and its
     * sections by UnitBlueprint handle.
     *
     * `yearOnly` is the shape the module keeps for a stay nobody remembers
     * the dates of any more — mutually exclusive with a date range, and
     * refused by CampService::validate() if both are given. One entry uses
     * it, because a dataset in which every stay has exact dates never shows
     * that column doing anything.
     *
     * @var list<array{
     *   place: string, sections: list<string>, stayType: string, status: string,
     *   start: ?string, end: ?string, yearOnly: ?int, price: ?string,
     *   participants: ?int, bookedByName: ?string
     * }>
     */
    public const CAMPS = [
        // --- Passés ------------------------------------------------------
        [
            'place' => 'bomal', 'sections' => ['lou1'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2022-07-21', 'end' => '2022-07-31',
            'yearOnly' => null, 'price' => '1450,00', 'participants' => 34, 'bookedByName' => 'Staff Meute de Seeonee',
        ],
        [
            'place' => 'grandpre', 'sections' => ['bal1'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2024-07-24', 'end' => '2024-07-27',
            'yearOnly' => null, 'price' => '620,00', 'participants' => 22, 'bookedByName' => 'Staff Ribambelle Bleue',
        ],
        [
            'place' => 'grandpre', 'sections' => ['pio1'], 'stayType' => Camp::STAY_WEEKEND,
            'status' => Camp::STATUS_CANCELLED, 'start' => '2025-03-14', 'end' => '2025-03-16',
            'yearOnly' => null, 'price' => null, 'participants' => null, 'bookedByName' => null,
        ],
        [
            'place' => 'sapiniere', 'sections' => ['ecl1'], 'stayType' => Camp::STAY_WEEKEND,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2024-11-15', 'end' => '2024-11-17',
            'yearOnly' => null, 'price' => '480,00', 'participants' => 31, 'bookedByName' => 'Staff Troupe du Faucon',
        ],
        [
            'place' => 'sapiniere', 'sections' => ['bal1', 'bal2'], 'stayType' => Camp::STAY_WEEKEND,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2026-05-22', 'end' => '2026-05-24',
            'yearOnly' => null, 'price' => '510,00', 'participants' => 28, 'bookedByName' => null,
        ],
        [
            'place' => 'bomal', 'sections' => ['lou2'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_CONFIRMED, 'start' => null, 'end' => null,
            'yearOnly' => 2021, 'price' => null, 'participants' => 26, 'bookedByName' => null,
        ],
        [
            'place' => 'bomal', 'sections' => ['lou1', 'lou2'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2025-07-23', 'end' => '2025-07-30',
            'yearOnly' => null, 'price' => '1980,00', 'participants' => 46, 'bookedByName' => 'Staff Meutes',
        ],
        [
            'place' => 'vresse', 'sections' => ['ecl1'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2026-07-20', 'end' => '2026-07-31',
            'yearOnly' => null, 'price' => '3120,00', 'participants' => 37, 'bookedByName' => 'Staff Troupe du Faucon',
        ],
        [
            'place' => 'chevetogne', 'sections' => ['iam1'], 'stayType' => Camp::STAY_OTHER,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2025-08-04', 'end' => '2025-08-09',
            'yearOnly' => null, 'price' => '740,00', 'participants' => 9, 'bookedByName' => null,
        ],
        // --- À venir -----------------------------------------------------
        [
            'place' => 'vresse', 'sections' => ['ecl1'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_TO_CONFIRM, 'start' => '2027-07-19', 'end' => '2027-07-30',
            'yearOnly' => null, 'price' => null, 'participants' => null, 'bookedByName' => null,
        ],
        [
            'place' => 'grandpre', 'sections' => ['bal1', 'bal2'], 'stayType' => Camp::STAY_GRAND_CAMP,
            'status' => Camp::STATUS_CONFIRMED, 'start' => '2027-07-24', 'end' => '2027-07-27',
            'yearOnly' => null, 'price' => '690,00', 'participants' => 30, 'bookedByName' => 'Staff Baladins',
        ],
        [
            'place' => 'chevetogne', 'sections' => ['pio1'], 'stayType' => Camp::STAY_OTHER,
            'status' => Camp::STATUS_TO_CONFIRM, 'start' => '2028-04-07', 'end' => '2028-04-10',
            'yearOnly' => null, 'price' => null, 'participants' => null, 'bookedByName' => null,
        ],
    ];
}
