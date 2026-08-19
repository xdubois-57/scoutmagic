<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;

/**
 * Scopes a file to the members of one group: owner_type
 * 'discussion_group', owner_id = discussion_groups.id (ARCHITECTURE.md
 * §8.3). Registered from the module's very first iteration, before
 * anything stored such a file, precisely so a file could never exist
 * ahead of the rule that guards it — three things rely on it now: a
 * post's media, a reply's single image (both gallery_media inside the
 * group's delegated album, reached through the twin
 * Gallery\GroupDelegatedAlbumAccessChecker) and a link preview's cached
 * image (a plain `files` row, guarded here directly).
 *
 * Fail-closed twice over: the registry denies any owner_type with no
 * checker (so every group file is denied while this module is disabled),
 * and this checker denies anything it cannot positively resolve to a
 * current member. There is no chief bypass — a chief who is not in the
 * group does not get the file — while a site admin passes because the
 * module makes them an implicit moderator everywhere.
 */
class GroupFileOwnershipChecker implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'discussion_group';

    public function __construct(
        private GroupRepository $groupRepository,
        private GroupAccessService $accessService,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        $group = $this->groupRepository->findById($ownerId);
        if ($group === null) {
            return false;
        }

        // The effective year is re-resolved here rather than carried in,
        // for the same reason the rest of the module re-resolves it every
        // request: a file request is its own request, and a stale year
        // would quietly change who counts as a derived member. No session
        // override is applied — a file is served on its own merits, not
        // through whatever year the visitor is currently browsing.
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(null, $currentRole);

        $context = new GroupSessionContext(
            null,
            $currentRole,
            array_values($linkedMemberIds),
            $effectiveYear->id,
            false
        );

        return $this->accessService->canRead($group, $context);
    }
}
