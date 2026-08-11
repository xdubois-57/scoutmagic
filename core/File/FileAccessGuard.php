<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

use Core\Security\Role;

class FileAccessGuard
{
    /**
     * @param array<int, int> $linkedMemberIds Persistent members.id values the current
     *     session is linked to (e.g. via blind-index email match) — used only for the
     *     owner-scoping checks below, never for role_min itself.
     * @param FileOwnershipCheckerInterface[] $ownershipCheckers Registry consulted when
     *     a file carries owner_type (schema/core.sql's generic polymorphic ownership,
     *     distinct from the owner_member_id special case below) — one checker per
     *     owner_type, e.g. Core\Member\SectionDocumentOwnershipChecker for
     *     'section_document'. A file whose owner_type has no matching checker is denied
     *     (fail-safe), same posture as RBAC's own "no role_min = no access" default.
     */
    public function __construct(
        private FileRepository $fileRepository,
        private Role $currentRole,
        private array $linkedMemberIds = [],
        private array $ownershipCheckers = []
    ) {
    }

    /**
     * Check if the current user can access a file.
     * Returns the file record if allowed, null if denied or not found.
     *
     * The usual role_min floor is always required, plus — independently —
     * whichever of the two ownership mechanisms the file actually uses:
     * owner_member_id (e.g. a member's private document — the current
     * session must be linked to that exact member) or owner_type/owner_id
     * (the generic registry above). Neither has a chief/admin bypass: a
     * higher role_min alone never substitutes for being the actual owner
     * or satisfying the registered checker.
     */
    public function check(int $fileId): ?FileRecord
    {
        $file = $this->fileRepository->findById($fileId);

        if ($file === null) {
            return null;
        }

        $requiredRole = Role::fromString($file->roleMin);

        if (!$this->currentRole->hasAccess($requiredRole)) {
            return null;
        }

        if ($file->ownerMemberId !== null && !in_array($file->ownerMemberId, $this->linkedMemberIds, true)) {
            return null;
        }

        if ($file->ownerType !== null) {
            \assert($file->ownerId !== null);
            if (!$this->isAllowedByRegistry($file->ownerType, $file->ownerId)) {
                return null;
            }
        }

        return $file;
    }

    private function isAllowedByRegistry(string $ownerType, int $ownerId): bool
    {
        foreach ($this->ownershipCheckers as $checker) {
            if ($checker->supports($ownerType)) {
                return $checker->isAllowed($ownerId, $this->currentRole, $this->linkedMemberIds);
            }
        }

        return false;
    }
}
