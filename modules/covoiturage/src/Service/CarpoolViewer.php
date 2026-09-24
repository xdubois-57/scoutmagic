<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Security\Role;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\SeatRequest;

/**
 * Who is looking, resolved once per request, and the visibility rules of
 * the module written against it — D8 of the chantier, one method per line
 * of its table:
 *
 * | Who                          | Sees                                         |
 * |------------------------------|----------------------------------------------|
 * | the driver                   | every request on their own offers            |
 * | the requester                | their own request                            |
 * | staff of a linked section    | every offer and passenger of that carpool    |
 * | Staff d'U and super-admin    | everything                                   |
 *
 * Everybody identified sees the carpools and the offers themselves — the
 * driver's name, the meeting point, the time, the seats left: that is what
 * the page is for. What is gated is WHO RIDES, and the phone numbers.
 *
 * **The staff view is additive (D1).** A chief who drives is a driver here
 * like anybody else; being staff adds visibility and takes nothing away.
 *
 * **A phone number goes to the other party of an ACCEPTED request, and to
 * nobody else (D6)** — not to the staff, whose role is to see who travels
 * in which car, not to call the families on their behalf.
 */
final class CarpoolViewer
{
    /**
     * @param list<int> $staffedSectionIds sections this account is an
     *        animateur of (Core\Member\SectionStaffAuthorizationService)
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $email,
        public readonly Role $role,
        public readonly int $scoutYearId,
        public readonly array $staffedSectionIds = []
    ) {
    }

    /** Staff d'U (chef d'unité) and super-admin: everything (D8). */
    public function seesEverything(): bool
    {
        return $this->role->hasAccess(Role::ADMIN);
    }

    /** Staff of one of the sections this carpool concerns (D3). */
    public function isStaffOf(Carpool $carpool): bool
    {
        if (!$this->role->hasAccess(Role::CHIEF)) {
            return false;
        }

        return array_intersect($carpool->sectionIds(), $this->staffedSectionIds) !== [];
    }

    /** Whether this reader sees every passenger of a carpool. */
    public function seesPassengersOf(Carpool $carpool): bool
    {
        return $this->seesEverything() || $this->isStaffOf($carpool);
    }

    /** Only a chief creates a carpool (D1). */
    public function mayCreate(): bool
    {
        return $this->role->hasAccess(Role::CHIEF);
    }

    /**
     * Who may edit or delete a carpool: its creator, the staff it concerns,
     * the Staff d'U.
     */
    public function mayOrganize(Carpool $carpool): bool
    {
        if (!$this->role->hasAccess(Role::CHIEF)) {
            return false;
        }

        return $this->seesPassengersOf($carpool) || $carpool->createdBy === $this->accountId;
    }

    public function drives(Offer $offer): bool
    {
        return $offer->driverAccountId === $this->accountId;
    }

    /** Every request on an offer: its driver, and the staff of the carpool. */
    public function seesRequestsOf(Carpool $carpool, Offer $offer): bool
    {
        return $this->drives($offer) || $this->seesPassengersOf($carpool);
    }

    public function sees(Carpool $carpool, Offer $offer, SeatRequest $request): bool
    {
        return $request->requesterAccountId === $this->accountId || $this->seesRequestsOf($carpool, $offer);
    }

    /** The driver's phone: to a requester whose request was accepted. */
    public function seesDriverPhone(SeatRequest $request): bool
    {
        return $request->requesterAccountId === $this->accountId && $request->isAccepted();
    }

    /** A requester's phone: to the driver, once the request is accepted. */
    public function seesRequesterPhone(Offer $offer, SeatRequest $request): bool
    {
        return $this->drives($offer) && $request->isAccepted();
    }
}
