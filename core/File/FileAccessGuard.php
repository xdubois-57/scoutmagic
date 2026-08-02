<?php

declare(strict_types=1);

namespace Core\File;

use Core\Security\Role;

class FileAccessGuard
{
    /**
     * @param array<int, int> $linkedMemberIds Persistent members.id values the current
     *     session is linked to (e.g. via blind-index email match) — used only for the
     *     owner-scoping check below, never for role_min itself.
     */
    public function __construct(
        private FileRepository $fileRepository,
        private Role $currentRole,
        private array $linkedMemberIds = []
    ) {
    }

    /**
     * Check if the current user can access a file.
     * Returns the file record if allowed, null if denied or not found.
     *
     * Two independent checks, both required: the usual role_min floor,
     * plus — when the file carries an owner_member_id (Core\File\
     * FileRecord::$ownerMemberId, e.g. a member's private document) — the
     * current session must be linked to that exact member. This is
     * deliberately generic (any file, not just member_documents) and has
     * NO chief/admin bypass: a higher role_min alone never substitutes for
     * being the actual owner, so a chief browsing with a role_min that
     * would otherwise satisfy the file's floor still cannot fetch another
     * member's owner-scoped document.
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

        return $file;
    }
}
