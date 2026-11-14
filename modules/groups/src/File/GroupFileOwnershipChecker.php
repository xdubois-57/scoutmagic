<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\File;

use Core\File\FileLevelAccessPolicyInterface;
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
 *
 * **One file at a time, too** (issue #1119): a file of content moderation
 * hid — a hidden post's link-preview image, the original of a hidden
 * post's or reply's photo — is refused to anyone but a moderator, through
 * the same Service\GroupAccessService::canReachModeratedContent() every
 * post-scoped path asks ({@see isFileAllowed()}). The address a member
 * saw before the hiding then serves nothing.
 */
class GroupFileOwnershipChecker implements FileOwnershipCheckerInterface, FileLevelAccessPolicyInterface
{
    public const OWNER_TYPE = 'discussion_group';

    public function __construct(
        private GroupRepository $groupRepository,
        private GroupAccessService $accessService,
        private ScoutYearResolver $scoutYearResolver,
        private HiddenGroupFiles $hiddenFiles
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

        return $this->accessService->canRead($group, $this->contextFor($currentRole, $linkedMemberIds));
    }

    /**
     * Asked by Core\File\FileAccessGuard once isAllowed() said yes: a file
     * of hidden content is a moderator's only — the login decides, since a
     * moderator flag belongs to one.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isFileAllowed(
        int $ownerId,
        int $fileId,
        Role $currentRole,
        array $linkedMemberIds,
        ?int $userAccountId
    ): bool {
        $group = $this->groupRepository->findById($ownerId);
        if ($group === null) {
            return false;
        }
        if (!$this->hiddenFiles->isFileOfHiddenContent($group, $fileId)) {
            return true;
        }

        return $this->accessService->canReachModeratedContent(
            $group,
            true,
            $this->contextFor($currentRole, $linkedMemberIds, $userAccountId)
        );
    }

    /**
     * The session a file or media request is decided for, rebuilt from
     * what the request carries — shared with Gallery\
     * GroupDelegatedAlbumAccessChecker, whose per-media rule also needs
     * the login ($userAccountId): moderating a group is a grant to one
     * login, not to the members it reaches.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function contextFor(
        Role $currentRole,
        array $linkedMemberIds,
        ?int $userAccountId = null
    ): GroupSessionContext {
        // The effective year is re-resolved here rather than carried in,
        // for the same reason the rest of the module re-resolves it every
        // request: a file request is its own request, and a stale year
        // would quietly change who counts as a derived member. No session
        // override is applied — a file is served on its own merits, not
        // through whatever year the visitor is currently browsing.
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(null, $currentRole);

        return new GroupSessionContext(
            $userAccountId,
            $currentRole,
            array_values($linkedMemberIds),
            $effectiveYear->id,
            false
        );
    }
}
