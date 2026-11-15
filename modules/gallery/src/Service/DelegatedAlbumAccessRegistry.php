<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;
use Modules\Gallery\Api\DelegatedMediaAccessChecker;

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

    /**
     * isAllowed() for one media of the album: the album-wide rule first,
     * then the media's own when the owner's checker has one (Api\
     * DelegatedMediaAccessChecker — a group hides the images of a post
     * moderation hid, issue #1119). What serves or hands out ONE media asks
     * this, never isAllowed() alone.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isMediaAllowed(
        string $ownerType,
        int $ownerId,
        int $mediaId,
        Role $currentRole,
        array $linkedMemberIds,
        ?int $userAccountId
    ): bool {
        foreach ($this->checkers as $checker) {
            if (!$checker->supports($ownerType)) {
                continue;
            }
            if (!$checker->isAllowed($ownerId, $currentRole, $linkedMemberIds)) {
                return false;
            }

            return !$checker instanceof DelegatedMediaAccessChecker
                || $checker->isMediaAllowed($ownerId, $mediaId, $currentRole, $linkedMemberIds, $userAccountId);
        }

        return false;
    }
}
