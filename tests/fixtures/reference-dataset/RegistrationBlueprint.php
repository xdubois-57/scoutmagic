<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Modules\Registration\Repository\RegistrationRequest;

/**
 * The registration desk, as data: what the capacities are and who has asked.
 *
 * **The shape of the numbers is the whole point.** A registration page where
 * nothing is nearly full teaches a reader nothing, and one where everything
 * is full teaches them the wrong thing. So the requests below pile up on the
 * first year of the path — the Baladins, which is where a real unit's queue
 * forms — and thin out further up.
 *
 * Three things about capacities, all of them lane J's semantics and all of
 * them exercised here rather than described:
 *
 *  - `SlotService::seedMissingCapacities()` writes a real
 *    `SlotService::DEFAULT_CAPACITY` (15) into every slot that has no row at
 *    all. The builder calls it, so every slot starts from a stored 15 rather
 *    than from an empty box.
 *  - **`null` means UNLIMITED**, never "full". CAPACITY_OVERRIDES sets one
 *    slot to null so that the "sans limite" path is a row in the database
 *    rather than a sentence in a comment.
 *  - **`0` means DELIBERATELY CLOSED**, which is a different thing from
 *    unlimited and from "we ran out". One slot carries it, for the same
 *    reason.
 *
 * The declared requests give the Baladins' first year thirteen accepted
 * demands against a capacity of fifteen. Nothing feeds that slot (it is the
 * first year of the first branch, so `SlotMath::feederSlot()` returns null
 * and the projected headcount is zero), which makes the remaining count
 * exactly `15 − 13 = 2` — "limited" on the public page, and visibly near the
 * limit without a single branch being full.
 *
 * All names, addresses and phone numbers are invented; the emails use the
 * RFC 2606 reserved domains, and the postal codes are real Belgian ones for
 * the reason UnitBlueprint::TOWNS gives.
 */
final class RegistrationBlueprint
{
    /**
     * The scout year the requests are for.
     *
     * Pinned rather than derived from "next year": the site's own current
     * year is date-computed (README §5), so a derived target would put the
     * requests in a different year depending on the day the build ran, and
     * the capacity arithmetic above would stop meaning anything. This is the
     * year AFTER the dataset's last one, which is exactly what a family
     * registering in spring is asking for.
     */
    public const TARGET_YEAR = '2027-2028';

    /**
     * The value `registration_form_open` carries at the end of the build.
     *
     * Open (`'1'`), deliberately: a closed form renders a "les inscriptions
     * sont fermées" page and nothing else, which hides every surface this
     * part of the dataset exists to fill.
     *
     * A string rather than a bool because that is what a `boolean` setting
     * stores, and Core\Config\SettingService::set() takes the stored form —
     * converting here would be one conversion too many between a table and a
     * column that already agree.
     */
    public const FORM_OPEN = '1';

    /**
     * Capacities that are NOT the seeded default, by branch label and
     * year-in-branch.
     *
     * @var list<array{branch: string, yearInBranch: int, capacity: ?int, why: string}>
     */
    public const CAPACITY_OVERRIDES = [
        [
            'branch' => 'Pionniers', 'yearInBranch' => 2, 'capacity' => null,
            'why' => "Pas de limite : à seize ans on ne refuse personne.",
        ],
        [
            'branch' => 'Éclaireurs', 'yearInBranch' => 4, 'capacity' => 0,
            'why' => "Fermé volontairement : cette tranche passe chez les Pionniers l'an prochain.",
        ],
        [
            'branch' => 'Louveteaux', 'yearInBranch' => 1, 'capacity' => 18,
            'why' => "Une meute un peu plus large que l'autre, décidée en staff.",
        ],
    ];

    /**
     * The requests.
     *
     * `branch` + `yearInBranch` decide the child's birth YEAR — the seeder
     * asks Modules\Registration\Service\SlotMath for it, rather than a date
     * written here that would silently fall into the wrong slot the day the
     * federation moves an age bracket. `birthMonthDay` is all that is left
     * to declare.
     *
     * `section` is the family's preferred section, by UnitBlueprint handle,
     * or null when they expressed none — which is the majority of a real
     * intake and must therefore be the majority here.
     *
     * @var list<array{
     *   branch: string, yearInBranch: int, status: string, section: ?string,
     *   parent: string, lastName: string, firstName: string, gender: string,
     *   birthMonthDay: string, street: string, number: string, postalCode: string,
     *   city: string, email: string, phone1: string, phone2: ?string, remarks: ?string
     * }>
     */
    public const REQUESTS = [
        // --- Baladins, première année : la file d'attente de l'unité ------
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'bal1',
            'parent' => 'Sophie Bastin', 'lastName' => 'Bastin', 'firstName' => 'Louise', 'gender' => 'F', 'birthMonthDay' => '02-11',
            'street' => 'Rue du Feu de Camp', 'number' => '12', 'postalCode' => '1300', 'city' => 'Wavre',
            'email' => 'famille.bastin@example.org', 'phone1' => '+32 470 00 20 01', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Marc Beauduin', 'lastName' => 'Beauduin', 'firstName' => 'Nathan', 'gender' => 'M', 'birthMonthDay' => '04-03',
            'street' => 'Avenue des Quatre Vents', 'number' => '7', 'postalCode' => '1310', 'city' => 'La Hulpe',
            'email' => 'famille.beauduin@example.org', 'phone1' => '+32 470 00 20 02', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Claire Collard', 'lastName' => 'Collard', 'firstName' => 'Manon', 'gender' => 'F', 'birthMonthDay' => '06-27',
            'street' => 'Clos de la Hutte', 'number' => '33', 'postalCode' => '1330', 'city' => 'Rixensart',
            'email' => 'famille.collard@example.org', 'phone1' => '+32 470 00 20 03', 'phone2' => '+32 2 000 20 03', 'remarks' => "Sa grande sœur est déjà chez les Louveteaux."],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'bal2',
            'parent' => 'Pierre Cornil', 'lastName' => 'Cornil', 'firstName' => 'Jules', 'gender' => 'M', 'birthMonthDay' => '09-15',
            'street' => 'Chemin des Marmites', 'number' => '80', 'postalCode' => '1348', 'city' => 'Louvain-la-Neuve',
            'email' => 'famille.cornil@example.org', 'phone1' => '+32 470 00 20 04', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Julie Delvaux', 'lastName' => 'Delvaux', 'firstName' => 'Chloé', 'gender' => 'F', 'birthMonthDay' => '01-08',
            'street' => 'Rue de la Corde Lisse', 'number' => '5', 'postalCode' => '1400', 'city' => 'Nivelles',
            'email' => 'famille.delvaux@example.org', 'phone1' => '+32 470 00 20 05', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Thomas Denis', 'lastName' => 'Denis', 'firstName' => 'Arthur', 'gender' => 'M', 'birthMonthDay' => '11-02',
            'street' => 'Drève du Totem', 'number' => '19', 'postalCode' => '1420', 'city' => "Braine-l'Alleud",
            'email' => 'famille.denis@example.org', 'phone1' => '+32 470 00 20 06', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'bal1',
            'parent' => 'Anne Dumont', 'lastName' => 'Dumont', 'firstName' => 'Emma', 'gender' => 'F', 'birthMonthDay' => '03-22',
            'street' => 'Sentier des Sizaines', 'number' => '44', 'postalCode' => '1470', 'city' => 'Genappe',
            'email' => 'famille.dumont@example.org', 'phone1' => '+32 470 00 20 07', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Olivier Fontaine', 'lastName' => 'Fontaine', 'firstName' => 'Victor', 'gender' => 'M', 'birthMonthDay' => '05-30',
            'street' => 'Rue du Foulard', 'number' => '61', 'postalCode' => '1490', 'city' => 'Court-Saint-Étienne',
            'email' => 'famille.fontaine@example.org', 'phone1' => '+32 470 00 20 08', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Nathalie Gilson', 'lastName' => 'Gilson', 'firstName' => 'Romane', 'gender' => 'F', 'birthMonthDay' => '07-19',
            'street' => 'Allée des Tentes Canadiennes', 'number' => '2', 'postalCode' => '1300', 'city' => 'Wavre',
            'email' => 'famille.gilson@example.org', 'phone1' => '+32 470 00 20 09', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'bal2',
            'parent' => 'Vincent Hallet', 'lastName' => 'Hallet', 'firstName' => 'Simon', 'gender' => 'M', 'birthMonthDay' => '10-05',
            'street' => 'Chaussée du Grand Feu', 'number' => '128', 'postalCode' => '1330', 'city' => 'Rixensart',
            'email' => 'famille.hallet@example.org', 'phone1' => '+32 470 00 20 10', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Isabelle Henrion', 'lastName' => 'Henrion', 'firstName' => 'Alice', 'gender' => 'F', 'birthMonthDay' => '12-12',
            'street' => 'Venelle des Sarbacanes', 'number' => '9', 'postalCode' => '1310', 'city' => 'La Hulpe',
            'email' => 'famille.henrion@example.org', 'phone1' => '+32 470 00 20 11', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Damien Jacquemin', 'lastName' => 'Jacquemin', 'firstName' => 'Hugo', 'gender' => 'M', 'birthMonthDay' => '08-24',
            'street' => 'Rue de la Boussole', 'number' => '37', 'postalCode' => '1400', 'city' => 'Nivelles',
            'email' => 'famille.jacquemin@example.org', 'phone1' => '+32 470 00 20 12', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Céline Lambert', 'lastName' => 'Lambert', 'firstName' => 'Zoé', 'gender' => 'F', 'birthMonthDay' => '05-06',
            'street' => 'Clos des Trois Nœuds', 'number' => '21', 'postalCode' => '1180', 'city' => 'Uccle',
            'email' => 'famille.lambert@example.org', 'phone1' => '+32 470 00 20 13', 'phone2' => null, 'remarks' => null],
        // Deux en attente : la file continue après que les places soient prises.
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_PENDING, 'section' => null,
            'parent' => 'Fabien Massart', 'lastName' => 'Massart', 'firstName' => 'Noé', 'gender' => 'M', 'birthMonthDay' => '02-28',
            'street' => 'Avenue du Sac à Dos', 'number' => '73', 'postalCode' => '1470', 'city' => 'Genappe',
            'email' => 'famille.massart@example.org', 'phone1' => '+32 470 00 20 14', 'phone2' => null, 'remarks' => "Nous déménageons dans la commune en juin."],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_PENDING, 'section' => 'bal1',
            'parent' => 'Laurence Nihoul', 'lastName' => 'Nihoul', 'firstName' => 'Camille', 'gender' => 'F', 'birthMonthDay' => '09-01',
            'street' => 'Chemin de la Popote', 'number' => '4', 'postalCode' => '1348', 'city' => 'Louvain-la-Neuve',
            'email' => 'famille.nihoul@example.org', 'phone1' => '+32 470 00 20 15', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_REFUSED, 'section' => null,
            'parent' => 'Gilles Pirard', 'lastName' => 'Pirard', 'firstName' => 'Élie', 'gender' => 'M', 'birthMonthDay' => '01-17',
            'street' => 'Rue des Piquets', 'number' => '58', 'postalCode' => '5000', 'city' => 'Namur',
            'email' => 'famille.pirard@example.org', 'phone1' => '+32 470 00 20 16', 'phone2' => null, 'remarks' => null],

        // --- Baladins, deuxième année ------------------------------------
        ['branch' => 'Baladins', 'yearInBranch' => 2, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => null,
            'parent' => 'Sarah Poncelet', 'lastName' => 'Poncelet', 'firstName' => 'Léa', 'gender' => 'F', 'birthMonthDay' => '04-14',
            'street' => 'Square de la Veillée', 'number' => '11', 'postalCode' => '1300', 'city' => 'Wavre',
            'email' => 'famille.poncelet@example.org', 'phone1' => '+32 470 00 20 17', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Baladins', 'yearInBranch' => 2, 'status' => RegistrationRequest::STATUS_WITHDRAWN, 'section' => null,
            'parent' => 'Bruno Renard', 'lastName' => 'Renard', 'firstName' => 'Martin', 'gender' => 'M', 'birthMonthDay' => '06-09',
            'street' => 'Chemin du Bivouac', 'number' => '90', 'postalCode' => '1420', 'city' => "Braine-l'Alleud",
            'email' => 'famille.renard@example.org', 'phone1' => '+32 470 00 20 18', 'phone2' => null, 'remarks' => "Finalement inscrit dans l'unité de son village."],

        // --- Plus haut dans le chemin : quelques demandes seulement -------
        ['branch' => 'Louveteaux', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'lou1',
            'parent' => 'Marie Simonis', 'lastName' => 'Simonis', 'firstName' => 'Justine', 'gender' => 'F', 'birthMonthDay' => '03-05',
            'street' => 'Rue du Feu de Camp', 'number' => '150', 'postalCode' => '1030', 'city' => 'Schaerbeek',
            'email' => 'famille.simonis@example.org', 'phone1' => '+32 470 00 20 19', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Louveteaux', 'yearInBranch' => 3, 'status' => RegistrationRequest::STATUS_PENDING, 'section' => null,
            'parent' => 'Éric Thiry', 'lastName' => 'Thiry', 'firstName' => 'Gauthier', 'gender' => 'M', 'birthMonthDay' => '11-21',
            'street' => 'Drève du Totem', 'number' => '66', 'postalCode' => '1050', 'city' => 'Ixelles',
            'email' => 'famille.thiry@example.org', 'phone1' => '+32 470 00 20 20', 'phone2' => null, 'remarks' => "Vient d'une autre unité, a déjà son totem."],
        ['branch' => 'Éclaireurs', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'ecl1',
            'parent' => 'Nadine Toussaint', 'lastName' => 'Toussaint', 'firstName' => 'Maud', 'gender' => 'F', 'birthMonthDay' => '07-02',
            'street' => 'Rue du Foulard', 'number' => '25', 'postalCode' => '1000', 'city' => 'Bruxelles',
            'email' => 'famille.toussaint@example.org', 'phone1' => '+32 470 00 20 21', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Éclaireurs', 'yearInBranch' => 2, 'status' => RegistrationRequest::STATUS_PENDING, 'section' => null,
            'parent' => 'Philippe Wathelet', 'lastName' => 'Wathelet', 'firstName' => 'Quentin', 'gender' => 'M', 'birthMonthDay' => '10-30',
            'street' => 'Chaussée du Grand Feu', 'number' => '3', 'postalCode' => '1330', 'city' => 'Rixensart',
            'email' => 'famille.wathelet@example.org', 'phone1' => '+32 470 00 20 22', 'phone2' => null, 'remarks' => null],
        ['branch' => 'Pionniers', 'yearInBranch' => 1, 'status' => RegistrationRequest::STATUS_ACCEPTED, 'section' => 'pio1',
            'parent' => 'Sylvie Willems', 'lastName' => 'Willems', 'firstName' => 'Thibault', 'gender' => 'M', 'birthMonthDay' => '01-26',
            'street' => 'Avenue des Quatre Vents', 'number' => '101', 'postalCode' => '1400', 'city' => 'Nivelles',
            'email' => 'famille.willems@example.org', 'phone1' => '+32 470 00 20 23', 'phone2' => null, 'remarks' => null],
    ];
}
