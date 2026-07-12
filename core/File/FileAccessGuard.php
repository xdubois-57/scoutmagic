<?php

declare(strict_types=1);

namespace Core\File;

use Core\Security\Role;

class FileAccessGuard
{
    public function __construct(
        private FileRepository $fileRepository,
        private Role $currentRole
    ) {
    }

    /**
     * Check if the current user can access a file.
     * Returns the file record if allowed, null if denied or not found.
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

        return $file;
    }
}
