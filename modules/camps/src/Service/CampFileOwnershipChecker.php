<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;

/**
 * Who may fetch a file this module stored — a stay's documents, a link's
 * preview image (ARCHITECTURE.md §8.3).
 *
 * Every camps file belongs to a stay, and every stay is readable by every
 * chief of the unit: this module has no per-camp visibility, so the
 * answer is the role and nothing else. Registering the checker at all is
 * what matters — FileAccessGuard narrows on the owner type, and a file
 * whose owner type nobody claims falls back to the row's own role_min
 * alone, which would leave a camp's contract readable by anyone the
 * generic rule lets through.
 */
class CampFileOwnershipChecker implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'camp_camp';

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        return $currentRole->hasAccess(Role::CHIEF);
    }
}
