<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;

/**
 * The access rule for a kept Desk CSV (`owner_type = 'desk_import'`,
 * `owner_id = import_journal.id`).
 *
 * That file is **the most concentrated personal-data artefact this system
 * holds**: names, dates of birth, addresses, telephone numbers, e-mail
 * addresses, formation level and handicap for the entire unit, in clear,
 * in one document. Denser than anything else on the disk.
 *
 * The rule itself is not subtle — chef d'unité and above, exactly the
 * `role_min` the `FileRecord` already carries. Stating it here anyway is
 * deliberate, and it is the only reason this class exists:
 *
 * - `Core\File\FileAccessGuard` denies any file whose `owner_type` has no
 *   registered checker, so the marker cannot be attached to the file
 *   without an explicit rule going with it;
 * - a `role_min` is one column and one careless `UPDATE` away from being
 *   wrong, while this is code somebody has to mean to change;
 * - and it gives `Core\Http\Controller\FileController` the marker it needs
 *   to journal every successful download (SECURITY.md §13), which it does
 *   for no other role-gated file.
 */
class DeskImportFileOwnershipChecker implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'desk_import';

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        // No owner scoping: the file describes the whole unit, so there is
        // no member it belongs to. Being a chef d'unité is the whole rule.
        return $currentRole->hasAccess(Role::ADMIN);
    }
}
