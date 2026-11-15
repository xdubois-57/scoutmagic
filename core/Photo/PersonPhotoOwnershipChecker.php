<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\FileOwnershipCheckerInterface;
use Core\File\ReducedRenditionPolicyInterface;
use Core\Security\Role;

/**
 * Who may read a person's photograph at full resolution (issue #1108).
 *
 * Two owner types, both stamped when the photo is linked
 * (MemberPhotoRepository, AccountPhotoRepository) and backfilled once for
 * the photos that predate it, each served by its own instance — the
 * registry asks a checker about an owner id without saying which type it
 * belongs to, and a member id and an account id must never be compared to
 * each other's list:
 *
 * - {@see forMemberPhotos()}: `member_photo`, owner = `members.id` — the
 *   member themselves and the parents linked to them (the sessions whose
 *   linked members include it), and `chief` and above;
 * - {@see forAccountPhotos()}: `account_photo`, owner = `user_accounts.id`
 *   — the account holder, and `chief` and above.
 *
 * **The reduced renditions keep their audience.** Every screen that shows
 * somebody else's face — a staff's trombinoscope, a group's member list,
 * an avatar — asks for `/files/{id}/thumb`, and those stay at the file's
 * `role_min` (`identified`). No screen needs a stranger's original, and
 * the original is what any signed-in account could read with nothing
 * linking it to the person: another section's parent, a former member.
 *
 * Built per request, with the account the session is signed in as, the
 * way FileAccessGuard itself is.
 */
final class PersonPhotoOwnershipChecker implements FileOwnershipCheckerInterface, ReducedRenditionPolicyInterface
{
    public const OWNER_MEMBER = 'member_photo';
    public const OWNER_ACCOUNT = 'account_photo';

    /** The floor that reads every original: the staff who already see the roster's faces. */
    private const STAFF_ROLE = Role::CHIEF;

    private function __construct(
        private readonly string $ownerType,
        private readonly ?int $currentUserAccountId
    ) {
    }

    public static function forMemberPhotos(): self
    {
        return new self(self::OWNER_MEMBER, null);
    }

    public static function forAccountPhotos(?int $currentUserAccountId): self
    {
        return new self(self::OWNER_ACCOUNT, $currentUserAccountId);
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === $this->ownerType;
    }

    /**
     * The rule for the ORIGINAL — a reduced rendition is let through
     * before this is asked (allowsReducedRendition()).
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        if ($currentRole->hasAccess(self::STAFF_ROLE)) {
            return true;
        }

        return $this->ownerType === self::OWNER_MEMBER
            ? in_array($ownerId, $linkedMemberIds, true)
            : $this->currentUserAccountId !== null && $ownerId === $this->currentUserAccountId;
    }

    public function allowsReducedRendition(int $ownerId): bool
    {
        return true;
    }
}
