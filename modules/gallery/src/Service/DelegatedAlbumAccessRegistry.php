<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;

/**
 * The registry Controller\GalleryController::serveMedia() consults for a
 * delegated album — mirrors Core\File\FileAccessGuard::isAllowedByRegistry()
 * exactly: the first registered checker whose supports() matches wins, and
 * an owner_type with no matching checker is denied (fail-closed), never
 * allowed by default. Two checkers must never claim the same owner_type,
 * same rule as the core registry.
 *
 * Built in public/index.php from a plain array — empty by default, so a
 * fresh install of gallery alone (no delegating module installed/enabled)
 * denies every delegated album it might still hold rows for, rather than
 * granting access no checker was ever asked to confirm.
 */
class DelegatedAlbumAccessRegistry
{
    /**
     * @param DelegatedAlbumAccessChecker[] $checkers
     */
    public function __construct(private array $checkers = [])
    {
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(string $ownerType, int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        foreach ($this->checkers as $checker) {
            if ($checker->supports($ownerType)) {
                return $checker->isAllowed($ownerId, $currentRole, $linkedMemberIds);
            }
        }

        return false;
    }
}
