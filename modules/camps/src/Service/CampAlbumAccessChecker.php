<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;

/**
 * Who may view the photos of a stay's delegated album.
 *
 * Registered into gallery's OWN registry, separately from
 * Service\CampFileOwnershipChecker: the first gates /gallery/media/{id},
 * the second gates /files/{id}, and a module registering only one leaves
 * its media reachable through the other route. Both must agree, and here
 * they do — every chief of the unit sees every stay.
 */
class CampAlbumAccessChecker implements DelegatedAlbumAccessChecker
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
