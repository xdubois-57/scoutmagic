<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

use Core\Security\Role;

/**
 * A pluggable read-access rule for a delegated album's media — gallery's
 * own equivalent of Core\File\FileOwnershipCheckerInterface, one level up:
 * the same shape and the same fail-closed contract, applied to
 * gallery_albums.owner_type/owner_id instead of files.owner_type/owner_id
 * (a delegated album's derived renditions are not `files` rows — see
 * schema.sql). Any module that hosts an album through
 * DelegatedAlbumManager registers exactly one implementation, keyed by its
 * own owner_type, without Controller\GalleryController ever needing to
 * know that module exists.
 *
 * Consulted by Service\DelegatedAlbumAccessRegistry from
 * Controller\GalleryController::serveMedia() before anything else — a
 * delegated album whose owner_type has no registered checker is denied,
 * never allowed by default, same posture as
 * Core\File\FileAccessGuard::isAllowedByRegistry().
 */
interface DelegatedAlbumAccessChecker
{
    /**
     * Whether this checker is the one responsible for a given owner_type.
     */
    public function supports(string $ownerType): bool;

    /**
     * Whether the current requester may view the delegated album whose
     * owner_id is $ownerId. $linkedMemberIds mirrors
     * Core\File\FileAccessGuard's own — persistent members.id values the
     * current session is linked to.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool;
}
