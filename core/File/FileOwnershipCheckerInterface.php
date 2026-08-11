<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

use Core\Security\Role;

/**
 * A pluggable ownership rule for Core\File\FileAccessGuard's generic
 * owner_type/owner_id mechanism (schema/core.sql's files table) —
 * consulted after the guard's usual role_min check, never a substitute
 * for it. Any feature that needs finer-grained file access than a flat
 * role_min floor registers its own implementation (e.g.
 * Core\Member\SectionDocumentOwnershipChecker for owner_type =
 * 'section_document') without FileAccessGuard or FileController ever
 * needing to know that feature exists.
 */
interface FileOwnershipCheckerInterface
{
    /**
     * Whether this checker is the one responsible for a given owner_type.
     */
    public function supports(string $ownerType): bool;

    /**
     * Whether the current requester may access a file whose owner_id is
     * $ownerId. $linkedMemberIds mirrors FileAccessGuard's own —
     * persistent members.id values the current session is linked to.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool;
}
