<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalComplianceService;

/**
 * Who may fetch a compliance document (§6.33), plugged into
 * `Core\File\FileAccessGuard`'s generic `owner_type` registry
 * (ARCHITECTURE.md §8.3).
 *
 * **Only somebody who may manage that asset.** The file's own `role_min` is
 * `identified`, which by itself would let any logged-in visitor at every
 * insurance policy and communal authorisation the unit holds — this is what
 * actually protects them.
 *
 * Simpler than its sibling `RentalDocumentOwnershipChecker` for one reason:
 * a compliance entry belongs to an asset directly, with no booking in
 * between, so `$ownerId` **is** the asset id.
 */
class RentalComplianceOwnershipChecker implements FileOwnershipCheckerInterface
{
    public function __construct(
        private RentalAuthorizationService $authorizationService,
        /**
         * Resolved once at composition time — a checker is consulted inside
         * a file request, which is not the place to be deriving the current
         * scout year from today's date on every call.
         */
        private int $scoutYearId,
        private ?string $email
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === RentalComplianceService::FILE_OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        if ($this->email === null || $this->email === '') {
            return false;
        }

        return $this->authorizationService->canManageAssetId($this->email, $this->scoutYearId, $ownerId);
    }
}
