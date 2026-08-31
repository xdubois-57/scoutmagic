<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Modules\Rental\Booking\BookingStatus;

/**
 * The unit's hall and who has rented it, as data.
 *
 * **One asset, configured all the way.** A rental asset with no tariff, no
 * booking rules and no manager renders three empty panels and a public page
 * that quotes nothing — which is what a half-seeded module looks like and
 * exactly what this dataset must not contain. So the hall below has a
 * billing unit and a rate, a real set of constraints, and a designated
 * manager who is a member of the unit rather than a name typed into a box.
 *
 * **Seven bookings, spread across the lifecycle**, because a list where every
 * row says "confirmé" shows none of the module's own vocabulary:
 *
 *  - three past stays already `closed` — the archive a manager scrolls;
 *  - two `confirmed`, one of them straddling today, which is the "mid-
 *    contract" case: the dates are held, the money is partly in, and the
 *    settlement has not been drawn up;
 *  - one `received`, untouched — a request waiting for somebody to look at
 *    it, which is the only state that puts a number on the manager's badge;
 *  - one `refused`, so a final state that is not a success is in the data.
 *
 * Dates are absolute for the reason CampsBlueprint's are: a booking is a fact
 * about a period, not about a scout year. They straddle the dataset's own
 * three years so that "à venir" and "passées" both have content.
 *
 * Renters are invented associations and families; the emails are on RFC 2606
 * reserved domains and the phone numbers are structurally unassignable.
 */
final class RentalBlueprint
{
    /**
     * The asset itself. `type` must be one of RentalAssetService::
     * DEFAULT_TYPES unless the instance widened the setting, and `Salle` is
     * what a scout hall let out for a weekend actually is.
     */
    public const ASSET = [
        'name' => "Local d'unité — la Grange",
        'type' => 'Salle',
        'capacity' => 60,
        'quantity' => 1,
        'arrivalTime' => '16:00',
        'departureTime' => '12:00',
        'emergencyPhone' => '+32 470 00 30 00',
        'isPublic' => true,
        'billingUnit' => 'per_night',
    ];

    /**
     * The tariff. A flat per-night rate with a floor: the shape a unit hall
     * is almost always let on, and the one that makes the engine's
     * "minimum facturable" line visible on a two-night weekend.
     */
    public const PRICING = [
        'billingUnit' => 'per_night',
        'defaultUnitPriceCents' => 18000,
        'minimumAmountCents' => 30000,
        'minimumPersons' => null,
    ];

    /**
     * The booking rules.
     *
     * `allowedArrivalWeekdays` is Friday and Saturday only, which is both
     * realistic and the constraint whose effect is easiest to see on the
     * public calendar: a free Tuesday that cannot be asked for is shown like
     * the past, never like "occupé" (module spec §6.7).
     *
     * @var array{minNights: int, maxNights: int, minNoticeDays: int, maxHorizonDays: int, allowedArrivalWeekdays: list<int>, maxPersons: ?int, bufferNights: int}
     */
    public const CONSTRAINTS = [
        'minNights' => 1,
        'maxNights' => 7,
        'minNoticeDays' => 14,
        'maxHorizonDays' => 540,
        'allowedArrivalWeekdays' => [5, 6],
        'maxPersons' => 55,
        'bufferNights' => 0,
    ];

    /**
     * Who manages the hall. A Tiers, never a name: the manager is a member
     * of the unit, and Modules\Rental\Service\RentalManagerService::grant()
     * takes a member id — which is what makes the renter's contact details
     * follow the person when they change section or leave.
     *
     * `T0016` is the intendant d'unité of the scenarios (scenario 11), which
     * is who really answers the phone about the hall.
     */
    public const MANAGER_TIERS = 'T0016';

    /**
     * @var list<array{
     *   arrival: string, departure: string, persons: int, status: string,
     *   name: string, email: string, phone: ?string, organisation: ?string,
     *   purpose: ?string, comment: ?string
     * }>
     */
    public const BOOKINGS = [
        // --- Terminées ---------------------------------------------------
        [
            'arrival' => '2024-11-08', 'departure' => '2024-11-10', 'persons' => 34,
            'status' => BookingStatus::CLOSED->value,
            'name' => 'Bénédicte Charlier', 'email' => 'contact@example.org', 'phone' => '+32 470 00 31 01',
            'organisation' => 'Chorale du Sart', 'purpose' => 'Weekend de répétition', 'comment' => null,
        ],
        [
            'arrival' => '2025-02-21', 'departure' => '2025-02-23', 'persons' => 22,
            'status' => BookingStatus::CLOSED->value,
            'name' => 'Jean-Marc Piret', 'email' => 'jm.piret@example.com', 'phone' => '+32 470 00 31 02',
            'organisation' => null, 'purpose' => 'Anniversaire de famille', 'comment' => 'Nous serons une vingtaine.',
        ],
        [
            'arrival' => '2025-09-12', 'departure' => '2025-09-14', 'persons' => 48,
            'status' => BookingStatus::CLOSED->value,
            'name' => 'Aurélie Massin', 'email' => 'aurelie.massin@example.com', 'phone' => null,
            'organisation' => 'Unité voisine — Groupe ZZ002', 'purpose' => 'Weekend de staff', 'comment' => null,
        ],
        // --- En cours ----------------------------------------------------
        [
            'arrival' => '2026-08-28', 'departure' => '2026-09-04', 'persons' => 40,
            'status' => BookingStatus::CONFIRMED->value,
            'name' => 'Denis Wauters', 'email' => 'denis.wauters@example.com', 'phone' => '+32 470 00 31 03',
            'organisation' => 'Mouvement de jeunesse Les Alouettes', 'purpose' => 'Camp de rentrée',
            'comment' => 'Nous arrivons le vendredi en fin de journée.',
        ],
        [
            'arrival' => '2027-04-09', 'departure' => '2027-04-11', 'persons' => 30,
            'status' => BookingStatus::CONFIRMED->value,
            'name' => 'Fatima Bengelloun', 'email' => 'f.bengelloun@example.com', 'phone' => '+32 470 00 31 04',
            'organisation' => null, 'purpose' => 'Weekend associatif', 'comment' => null,
        ],
        // --- À traiter ---------------------------------------------------
        [
            'arrival' => '2027-06-04', 'departure' => '2027-06-06', 'persons' => 25,
            'status' => BookingStatus::RECEIVED->value,
            'name' => 'Sébastien Kerkhofs', 'email' => 'seb.kerkhofs@example.com', 'phone' => '+32 470 00 31 05',
            'organisation' => 'Club de marche du Brabant', 'purpose' => 'Weekend de marche',
            'comment' => "Y a-t-il de la place pour garer deux minibus ?",
        ],
        [
            'arrival' => '2027-05-14', 'departure' => '2027-05-16', 'persons' => 70,
            'status' => BookingStatus::REFUSED->value,
            'name' => 'Gaëtan Dupuis', 'email' => 'g.dupuis@example.com', 'phone' => null,
            'organisation' => null, 'purpose' => 'Soirée privée',
            'comment' => 'Nous serons environ septante.',
        ],
    ];

    /**
     * What the renter ticked. Both boxes are mandatory
     * (RentalBookingService::createFromPublicRequest() refuses without
     * them), and the second is deliberately NOT framed as consent: the
     * processing is necessary to the booking, and calling it consent would
     * misrepresent the legal basis (module spec §6.13).
     *
     * @var array{conditions_version: string, conditions_text: string, privacy_version: string, privacy_text: string}
     */
    public const ACCEPTANCES = [
        'conditions_version' => '2024-09-01',
        'conditions_text' => "Conditions de location du local d'unité — version de démonstration.",
        'privacy_version' => '2024-09-01',
        'privacy_text' => "Politique de confidentialité — version de démonstration.",
    ];
}
