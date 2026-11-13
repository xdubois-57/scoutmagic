<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Gallery;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;
use Modules\Gallery\Api\DelegatedMediaAccessChecker;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Service\GroupAccessService;

/**
 * Scopes a group's delegated gallery album (owner_type
 * GroupFileOwnershipChecker::OWNER_TYPE, owner_id = discussion_groups.id)
 * to the group's own members — registered into Modules\Gallery\Service\
 * DelegatedAlbumAccessRegistry, gallery's SEPARATE registry for delegated
 * albums (gallery prompt 5), never Core\File\FileAccessGuard's own one.
 *
 * The album-wide rule is a thin adapter, not a second implementation of
 * the group-membership decision: both interfaces share the exact same
 * supports(string): bool / isAllowed(int, Role, int[]): bool shape (a
 * deliberate mirror, per Api\DelegatedAlbumAccessChecker's own docblock),
 * so isAllowed() simply delegates to File\GroupFileOwnershipChecker — the
 * file registry's own checker for the identical owner_type — rather than
 * duplicating Service\GroupAccessService::canRead() a second time. A
 * member who can read the group's posts can always see the group's media
 * too, and vice versa, by construction: there is only one decision
 * function, reached through two registries.
 *
 * The per-media rule (issue #1119) is the same idea one level down: an
 * image of a post or reply moderation hid is reachable exactly as that
 * post or reply is — by a moderator only — through Service\
 * GroupAccessService::canReachModeratedContent(), the rule every
 * post-scoped controller path asks. Otherwise the image that got a post
 * hidden would stay one /gallery/media/… URL away from every member.
 */
class GroupDelegatedAlbumAccessChecker implements DelegatedAlbumAccessChecker, DelegatedMediaAccessChecker
{
    public function __construct(
        private GroupFileOwnershipChecker $fileChecker,
        private GroupRepository $groupRepository,
        private GroupAccessService $accessService,
        private PostMediaRepository $postMediaRepository
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $this->fileChecker->supports($ownerType);
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        return $this->fileChecker->isAllowed($ownerId, $currentRole, $linkedMemberIds);
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isMediaAllowed(
        int $ownerId,
        int $mediaId,
        Role $currentRole,
        array $linkedMemberIds,
        ?int $userAccountId
    ): bool {
        if (!$this->postMediaRepository->isMediaOfHiddenContent($ownerId, $mediaId)) {
            return true;
        }

        $group = $this->groupRepository->findById($ownerId);

        return $group !== null && $this->accessService->canReachModeratedContent(
            $group,
            true,
            $this->fileChecker->contextFor($currentRole, $linkedMemberIds, $userAccountId)
        );
    }
}
