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
     * The one role that reads an owner-scoped file it does not own.
     *
     * `admin` and deliberately not `chief`: an animateur de section has no
     * reason to read an animé's tax certificate, and the Staff d'Unité is
     * already the floor of every page that would show one. Superadmin comes
     * along for free, being above it.
     */
    public const STAFF_BYPASS_ROLE = 'admin';

    /**
     * Check if the current user can access a file.
     * Returns the file record if allowed, null if denied or not found.
     *
     * The usual role_min floor is always required, plus — independently —
     * whichever of the two ownership mechanisms the file actually uses:
     * owner_member_id (e.g. a member's private document — the current
     * session must be linked to that exact member, OR hold the Staff
     * d'Unité role, see below) or owner_type/owner_id (the generic
     * registry above, which has no such bypass: a higher role_min never
     * substitutes for satisfying the registered checker).
     *
     * **`owner_member_id` HAS a Staff d'Unité bypass, and it is recent.**
     * This guard used to state the opposite — an owner-scoped file was
     * unreachable to staff who were not that member, full stop — and that
     * guarantee was withdrawn deliberately when the Staff d'Unité was given
     * the private documents block on the admin member page: a chef d'unité
     * answering « nous n'avons rien reçu » has to be able to open the
     * document and send it again, and a mechanism that forbids it turns
     * every such question into a fresh deposit. It is not compartmentable:
     * opening it for certificates opens it for everything owner-scoped
     * later, which is why the trade is written down here, in
     * ARCHITECTURE.md §8.3 and in SECURITY.md §6 rather than left to be
     * discovered. Two bounds keep it narrow: it stops at `admin`, and
     * `FileController` journals every such opening at `security` level
     * (identifiers only) — the audit trail is what replaces the wall.
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

        if ($this->isOwnerScopedAgainst($file) && !$this->hasStaffBypass()) {
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

    /**
     * Whether this access is the Staff d'Unité bypass rather than the
     * member's own reading — the question `FileController` asks to decide
     * which journal entry to write, and at which level.
     *
     * Pure: it re-asks the same two questions `check()` did, of the record
     * `check()` returned, rather than remembering anything between calls.
     */
    public function isStaffBypass(FileRecord $file): bool
    {
        return $this->isOwnerScopedAgainst($file) && $this->hasStaffBypass();
    }

    /** The file has an owner, and this session is not linked to them. */
    private function isOwnerScopedAgainst(FileRecord $file): bool
    {
        return $file->ownerMemberId !== null
            && !in_array($file->ownerMemberId, $this->linkedMemberIds, true);
    }

    private function hasStaffBypass(): bool
    {
        return $this->currentRole->hasAccess(Role::fromString(self::STAFF_BYPASS_ROLE));
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
