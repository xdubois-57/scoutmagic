<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalAuthorizationService;

/**
 * Who may fetch a rental document (§6.24), plugged into
 * `Core\File\FileAccessGuard`'s generic `owner_type` registry
 * (ARCHITECTURE.md §8.3/§7.4).
 *
 * The answer is short: **only somebody who may manage the asset the
 * document's booking belongs to.** The file's own `role_min` is
 * `identified`, which by itself would let any logged-in visitor at every
 * contract in the unit; this is what actually protects them, exactly as
 * `RentalAuthorizationService` protects every other managed-space surface.
 *
 * **A renter is never allowed here, and that is deliberate** (§6.24,
 * §6.26). An external renter has no account, and the tracking token is a
 * capability for *their own page*, not a file credential. Their contract
 * and invoice reach them by email and only by email; a lost email is
 * resent by a manager. Wiring the token into file access would be a new
 * exception to SECURITY.md §6 for the sake of saving one click, and the
 * spec is explicit that it must not exist.
 *
 * Resolution goes file → booking → asset because that is the only chain
 * that survives a booking being moved or an asset being renamed: nothing
 * is denormalised onto the file row, so nothing can drift.
 */
class RentalDocumentOwnershipChecker implements FileOwnershipCheckerInterface
{
    /** Matches Service\RentalDocumentService::OWNER_TYPE. */
    public const OWNER_TYPE = 'rental_document';

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalAuthorizationService $authorizationService,
        /**
         * Resolved once at composition time. A checker is consulted inside
         * a file request, which is not the place to be deriving the current
         * scout year from today's date on every call.
         */
        private int $scoutYearId,
        /**
         * The requesting session's email. Null for an anonymous visitor,
         * who is refused outright — this is the only identity the guard's
         * own `$linkedMemberIds` cannot express, because a manager grant is
         * per asset rather than per file.
         */
        private ?string $email
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        if ($this->email === null || $this->email === '') {
            return false;
        }

        $booking = $this->bookingRepository->findById($ownerId);
        if ($booking === null) {
            // The booking is gone but the file row survived — a restored
            // backup, a botched delete. Refusing is the only safe answer:
            // there is nobody left to check the request against.
            return false;
        }

        return $this->authorizationService->canManageAssetId(
            $this->email,
            $this->scoutYearId,
            $booking->assetId
        );
    }
}
